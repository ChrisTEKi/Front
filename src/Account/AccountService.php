<?php

namespace App\Account;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Auth\PasswordPolicy;
use App\Auth\VerificationResult;
use App\Entity\User;
use App\Enum\OtpPurpose;
use App\Notification\SecurityNotifier;
use App\Phone\PhoneNumberParser;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Réglages de SON compte, pour un utilisateur connecté : mot de passe, double authentification, téléphone,
 * suppression du compte.
 *
 * Toutes ces actions sont SENSIBLES : elles exigent une confirmation (mot de passe, ou code e-mail pour les comptes
 * Google/Apple) via Reauthenticator, sont écrites dans le journal d'audit et préviennent le titulaire par e-mail
 * (si ce n'est pas lui qui agit, il le saura tout de suite).
 *
 * Aucune méthode ne reçoit d'identifiant : on n'agit QUE sur l'utilisateur connecté, jamais sur un autre compte.
 */
final class AccountService
{
    public function __construct(
        private readonly Reauthenticator $reauth,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PhoneNumberParser $phoneParser,
        private readonly PhoneService $phones,
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit,
        private readonly SecurityNotifier $notifier,
        private readonly ClockInterface $clock,
    ) {
    }

    // =========================================================================
    // MOT DE PASSE
    // =========================================================================

    /**
     * Change le mot de passe. Les AUTRES sessions ouvertes (autres appareils, autres navigateurs) sont fermées : leur
     * empreinte du mot de passe ne correspond plus (voir User::isEqualTo). La session en cours reste ouverte : c'est la
     * personne qui vient de prouver qu'elle connaît l'ancien mot de passe.
     *
     * @throws AccountActionException « no_password », « step_up_failed », « step_up_locked », « same_password », « weak_password »
     */
    public function changePassword(User $user, string $current, string $new): void
    {
        // Un compte Google/Apple n'a pas de mot de passe à « changer » : il en définit un via « Mot de passe oublié ».
        if (!$user->hasPassword()) {
            throw new AccountActionException('no_password');
        }
        $this->reauth->require($user, $current, '', OtpPurpose::AccountSecurity);

        if ($this->hasher->isPasswordValid($user, $new)) {
            throw new AccountActionException('same_password');
        }
        $errors = $this->passwordPolicy->errors($new, $user->getEmail(), $user->isAdmin());
        if ([] !== $errors) {
            throw new AccountActionException('weak_password', ['%details%' => implode(' ', $errors)]);
        }

        $now = $this->clock->now();
        $user->setPasswordHash($this->hasher->hashPassword($user, $new), $now);
        $user->touch($now);
        $this->em->flush();

        $this->audit->record(AuditAction::PASSWORD_CHANGED, $user, 'user', $user->getId()->toRfc4122(), ['channel' => 'account']);
        $this->notifier->passwordChanged($user);
    }

    // =========================================================================
    // DOUBLE AUTHENTIFICATION (facultative pour les clients et fournisseurs)
    // =========================================================================

    /**
     * Active ou désactive les canaux de 2FA. Le SMS n'est proposé qu'avec un téléphone VÉRIFIÉ.
     *
     * @throws AccountActionException « admin_two_factor_mandatory », « sms_needs_verified_phone », confirmation refusée
     */
    public function setTwoFactor(User $user, bool $email, bool $sms, string $password, string $code): void
    {
        // La 2FA par e-mail des administrateurs est obligatoire : elle ne se règle pas ici.
        if ($user->isAdmin()) {
            throw new AccountActionException('admin_two_factor_mandatory');
        }
        if ($sms && !$user->isPhoneVerified()) {
            throw new AccountActionException('sms_needs_verified_phone');
        }
        $this->reauth->require($user, $password, $code, OtpPurpose::AccountSecurity);

        $before = ['email' => $user->isTwoFactorEmailEnabled(), 'sms' => $user->isTwoFactorSmsEnabled()];
        $user->setTwoFactor($email, $sms);
        $user->touch($this->clock->now());
        $this->em->flush();

        if ($before !== ['email' => $user->isTwoFactorEmailEnabled(), 'sms' => $user->isTwoFactorSmsEnabled()]) {
            $this->audit->record(AuditAction::TWO_FACTOR_CHANGED, $user, 'user', $user->getId()->toRfc4122(), [
                'before' => $before,
                'after' => ['email' => $user->isTwoFactorEmailEnabled(), 'sms' => $user->isTwoFactorSmsEnabled()],
            ]);
            $this->notifier->twoFactorChanged($user);
        }
    }

    // =========================================================================
    // TÉLÉPHONE
    // =========================================================================

    /**
     * Remplace le téléphone du compte (ou le retire si `$raw` est vide) puis envoie le code SMS de vérification.
     *
     * Ce parcours « léger » ne sert plus qu'à AJOUTER un premier numéro ou à CORRIGER un numéro pas encore vérifié : il faudra de
     * toute façon saisir le code SMS, et aucun numéro de confiance n'est remplacé. Changer ou retirer un numéro DÉJÀ vérifié est
     * sensible (il coupe la 2FA par SMS et sert à la livraison) : ce changement passe par la double confirmation de
     * ContactChangeService (code à l'ancienne adresse e-mail, puis SMS au nouveau numéro) — ici il est refusé.
     *
     * @throws AccountActionException « contact_change_required », « supplier_phone_required », « invalid_phone »
     */
    public function changePhone(User $user, string $raw): VerificationResult
    {
        $raw = trim($raw);
        $number = '' === $raw ? null : $this->phoneParser->parse($raw);
        if ('' !== $raw && null === $number) {
            throw new AccountActionException('invalid_phone');
        }
        // Même numéro déjà vérifié : rien à faire (et rien à confirmer).
        if (null !== $number && $user->isPhoneVerified() && $user->getPhone() === $number->e164) {
            return VerificationResult::verified(['phone' => $number->masked()]);
        }
        if ($user->isPhoneVerified()) {
            throw new AccountActionException('contact_change_required');
        }
        // Un fournisseur doit rester joignable : son numéro ne se retire pas.
        if (null === $number && $user->isSupplier()) {
            throw new AccountActionException('supplier_phone_required');
        }

        $before = $user->getPhone();
        $user->changePhone($number?->e164); // le nouveau numéro n'est jamais « vérifié » d'office ; la 2FA SMS est coupée
        $user->touch($this->clock->now());
        $this->em->flush();

        if ($before !== $user->getPhone()) {
            $this->audit->record(AuditAction::PHONE_CHANGED, $user, 'user', $user->getId()->toRfc4122(), ['removed' => null === $number]);
            $this->notifier->phoneChanged($user);
        }
        if (null === $number) {
            return VerificationResult::verified(['phone' => null]);
        }

        // Le numéro est maintenant enregistré et non vérifié : le parcours habituel envoie le code SMS.
        return $this->phones->start($user, $raw);
    }

    // =========================================================================
    // SUPPRESSION DU COMPTE (délai de 30 jours)
    // =========================================================================

    /**
     * Envoie le code de confirmation de suppression (comptes sans mot de passe).
     *
     * @return int secondes avant de pouvoir en redemander un
     *
     * @throws AccountActionException
     */
    public function sendDeletionCode(User $user): int
    {
        $this->assertCanDelete($user);

        return $this->reauth->sendCode($user, OtpPurpose::AccountDeletion);
    }

    /**
     * Programme la suppression : le compte est GELÉ (connexion impossible sauf pour le restaurer) pendant 30 jours,
     * puis effacé définitivement par la commande `app:accounts:purge`. Se reconnecter avant cette date permet de l'annuler.
     *
     * Refusée tant qu'une commande est en cours (le livreur et le suivi en dépendent).
     *
     * @return \DateTimeImmutable date de la suppression définitive
     *
     * @throws AccountActionException « deletion_not_available », « open_orders », confirmation refusée
     */
    public function requestDeletion(User $user, string $password, string $code): \DateTimeImmutable
    {
        $this->assertCanDelete($user);
        $this->reauth->require($user, $password, $code, OtpPurpose::AccountDeletion);

        $now = $this->clock->now();
        $user->requestDeletion($now);
        $user->touch($now);
        $this->em->flush();

        $this->audit->record(AuditAction::ACCOUNT_DELETION_REQUESTED, $user, 'user', $user->getId()->toRfc4122(), [
            'scheduled_at' => $user->getDeletionScheduledAt()?->format(\DATE_ATOM),
        ]);
        $this->notifier->deletionScheduled($user);

        return $user->getDeletionScheduledAt() ?? $now;
    }

    /** @throws AccountActionException */
    private function assertCanDelete(User $user): void
    {
        // Seuls les CLIENTS suppriment leur compte seuls : un fournisseur a des produits et des ventes à solder, un admin
        // est géré par le Super Admin (le support GoBuy traite ces cas).
        if (!$user->isClient() || !$user->isActive()) {
            throw new AccountActionException('deletion_not_available');
        }
        if ($this->orders->countOpenForClient($user) > 0) {
            throw new AccountActionException('open_orders');
        }
    }
}
