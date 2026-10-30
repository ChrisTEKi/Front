<?php

namespace App\Auth;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Auth\Exception\OtpDeliveryFailedException;
use App\Auth\Exception\OtpSendRefusedException;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Http\DeferredWork;
use App\Notification\SecurityNotifier;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « Mot de passe oublié » en deux temps :
 *   1. requestReset() : l'utilisateur saisit son e-mail → un code à 6 chiffres lui est envoyé ;
 *   2. reset()        : il saisit le code ET son nouveau mot de passe → le mot de passe est changé.
 *
 * Sécurité :
 *   - réponses NEUTRES : « si un compte existe, un code a été envoyé » (jamais « compte introuvable ») ;
 *   - le travail d'envoi est différé après la réponse (durée de réponse identique, voir DeferredWork) ;
 *   - quotas par IP et par e-mail ; 5 essais maximum par code ; code valable 10 min, à usage unique ;
 *   - changer le mot de passe DÉCONNECTE toutes les sessions existantes (l'empreinte du hash change, voir User::isEqualTo) ;
 *   - un avis par e-mail prévient le titulaire ;
 *   - le Super Admin n'a PAS de réinitialisation en libre-service (commande console réservée aux serveurs).
 */
final class PasswordResetService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly OtpService $otp,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly SecurityNotifier $notifier,
        private readonly DeferredWork $deferred,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactoryInterface $passwordResetIpLimiter,
        private readonly RateLimiterFactoryInterface $passwordResetEmailLimiter,
        private readonly RateLimiterFactoryInterface $otpVerifyIpLimiter,
    ) {
    }

    /**
     * Étape 1 : demande d'un code. Retourne toujours le même résultat vers l'extérieur (sauf quota IP dépassé).
     *
     * @return int 0 si la demande est acceptée, sinon le nombre de secondes à attendre (quota IP dépassé)
     */
    public function requestReset(string $email): int
    {
        $email = mb_strtolower(trim($email));
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';

        // Quota par IP : le seul cas où l'on refuse explicitement (il ne dépend pas de l'existence du compte).
        $ipLimit = $this->passwordResetIpLimiter->create($ip)->consume();
        if (!$ipLimit->isAccepted()) {
            return max(1, $ipLimit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());
        }

        // Quota par e-mail (3 / heure) : au-delà on ignore SILENCIEUSEMENT (réponse identique) pour ne pas
        // permettre de harceler une boîte mail ni de deviner les comptes.
        if (!$this->passwordResetEmailLimiter->create(hash('sha256', $email))->consume()->isAccepted()) {
            return 0;
        }

        // Le vrai travail (recherche + envoi) se fait APRÈS l'envoi de la réponse.
        $this->deferred->defer(function () use ($email): void {
            $user = $this->users->findOneByEmail($email);
            // Les administrateurs (Super Admin compris) n'ont PAS de réinitialisation en libre-service : leur boîte e-mail est déjà leur
            // 2e facteur (code de connexion), donc qui contrôlerait la boîte obtiendrait les deux. Le Super Admin leur renvoie un lien
            // d'activation (AdminAccountService::resetAccess).
            if (null === $user || $user->isSuspended() || $user->isAdmin()) {
                return; // silence : aucun e-mail, aucune trace visible
            }

            try {
                $this->otp->issue($user, OtpPurpose::PasswordReset, OtpChannel::Email, $user->getEmail());
                $this->audit->record(AuditAction::PASSWORD_RESET_REQUESTED, $user, 'user', $user->getId()->toRfc4122());
            } catch (OtpSendRefusedException|OtpDeliveryFailedException) {
                // Déjà limité/journalisé ; rien à montrer à l'écran.
            }
        });

        return 0;
    }

    /**
     * Étape 2 : code + nouveau mot de passe.
     */
    public function reset(string $email, string $code, string $newPassword): PasswordResetOutcome
    {
        $email = mb_strtolower(trim($email));
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';

        $limit = $this->otpVerifyIpLimiter->create($ip)->consume();
        if (!$limit->isAccepted()) {
            return new PasswordResetOutcome(false, 'too_many_attempts', retryAfter: max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()));
        }

        $user = $this->users->findOneByEmail($email);

        // 1) La force du mot de passe est contrôlée AVANT le code : un mot de passe refusé ne « brûle » pas le code.
        //    Les règles ne sont pas secrètes et sont identiques que le compte existe ou non (pas de fuite d'information).
        //    Une SEULE politique (celle des non-administrateurs) quel que soit le compte visé : une règle plus stricte pour les
        //    administrateurs révélerait, par la réponse, qu'une adresse est celle d'un administrateur.
        $fieldErrors = $this->passwordErrors($newPassword, $email, false);
        if ([] !== $fieldErrors) {
            return new PasswordResetOutcome(false, fieldErrors: ['password' => $fieldErrors]);
        }

        // 2) Le code. Message UNIQUE pour « compte inconnu », « mauvais code », « code périmé » : pas d'énumération.
        if (null === $user || $user->isSuspended() || $user->isAdmin()) {
            return new PasswordResetOutcome(false, 'invalid_code');
        }
        $check = $this->otp->verify($user, OtpPurpose::PasswordReset, $user->getEmail(), $code);
        if (!$check->isValid()) {
            return new PasswordResetOutcome(false, 'invalid_code');
        }

        // 3) Changement du mot de passe. Avoir reçu le code PROUVE la possession de la boîte mail : l'e-mail devient vérifié.
        $now = $this->clock->now();
        $user->setPasswordHash($this->hasher->hashPassword($user, $newPassword), $now);
        $user->markEmailVerified($now);
        $user->touch($now);
        $this->em->flush();

        $this->audit->record(AuditAction::PASSWORD_RESET_DONE, $user, 'user', $user->getId()->toRfc4122());
        $this->notifier->passwordChanged($user);

        return new PasswordResetOutcome(true);
    }

    /**
     * Messages d'erreur de force du mot de passe (liste vide = mot de passe accepté). Voir PasswordPolicy.
     *
     * @return list<string>
     */
    public function passwordErrors(string $password, string $email, bool $isAdmin): array
    {
        return $this->policy->errors($password, $email, $isAdmin);
    }
}
