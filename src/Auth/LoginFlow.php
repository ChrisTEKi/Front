<?php

namespace App\Auth;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Auth\Exception\OtpDeliveryFailedException;
use App\Auth\Exception\OtpSendRefusedException;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Enum\SocialProvider;
use App\Notification\SecurityNotifier;
use App\Phone\PhoneNumber;
use App\Repository\UserRepository;
use App\Security\AdminAccessPolicy;
use App\Security\FlowAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Chef d'orchestre du parcours de connexion et d'inscription.
 *
 * PRINCIPE
 * --------
 * Se connecter n'est pas une seule action mais une SUITE d'étapes, selon le compte :
 *
 *   identité prouvée (mot de passe / Google / Apple / inscription)
 *     → [vérifier l'e-mail]      si aucun contact vérifié (fournisseur : e-mail ET téléphone)
 *     → [vérifier le téléphone]  obligatoire pour un fournisseur ; facultative (« Plus tard ») après une inscription client
 *     → [double authentification] si activée (toujours pour un administrateur)
 *     → [restaurer le compte]    si une suppression était programmée
 *     → CONNECTÉ (jeton Symfony créé)
 *
 * Le SERVEUR calcule l'étape suivante (nextStep) à partir de l'état en base : le navigateur ne peut ni sauter
 * une étape ni en choisir une. Tant que toutes ne sont pas franchies, AUCUN jeton Symfony n'existe : la personne
 * n'est « à moitié connectée » à aucun moment.
 *
 * L'état du parcours est stocké dans la session serveur (voir FlowState).
 */
final class LoginFlow
{
    /** Longueur maximale d'un mot de passe accepté : évite d'imposer un hachage Argon2 sur un texte géant (déni de service). */
    private const MAX_PASSWORD_LENGTH = 1024;
    /** Taille de la colonne `users.email` : une adresse plus longue ne peut pas exister en base. */
    private const MAX_EMAIL_LENGTH = 180;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly OtpService $otp,
        private readonly PhoneVerifier $phones,
        private readonly Security $security,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly AdminAccessPolicy $adminAccess,
        private readonly LoginRedirector $redirector,
        private readonly SecurityNotifier $notifier,
        private readonly RateLimiterFactoryInterface $loginIpLimiter,
        private readonly RateLimiterFactoryInterface $loginAccountLimiter,
        private readonly RateLimiterFactoryInterface $otpVerifyIpLimiter,
    ) {
    }

    // =========================================================================
    // POINTS D'ENTRÉE : comment un parcours démarre
    // =========================================================================

    /**
     * Étape « mot de passe » : contrôle l'e-mail + le mot de passe puis lance le parcours.
     */
    public function attemptPassword(string $email, string $password): FlowOutcome
    {
        $email = mb_strtolower(trim($email));
        // Une adresse e-mail fait 180 caractères au plus (taille de la colonne `users.email`). Au-delà, ce n'est pas une adresse :
        // on coupe AVANT le limiteur et le journal d'audit (sinon un envoi de plusieurs Mo y serait recopié tel quel) et on
        // traite la tentative comme celle d'un compte inconnu (jamais de recherche avec une valeur tronquée).
        $tooLong = mb_strlen($email) > self::MAX_EMAIL_LENGTH;
        $email = mb_substr($email, 0, self::MAX_EMAIL_LENGTH);
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';

        // --- Anti force-brute, 1re couche : par adresse IP (20 essais / 10 min). ---
        $ipLimit = $this->loginIpLimiter->create($ip)->consume();
        if (!$ipLimit->isAccepted()) {
            $this->audit->record(AuditAction::LOGIN_BLOCKED, null, null, null, ['reason' => 'ip_rate_limit'], $email);

            return $this->tooManyAttempts($ipLimit->getRetryAfter());
        }

        // --- 2e couche : par compte (5 échecs / 15 min), quelle que soit l'IP → bloque les attaques distribuées. ---
        // La clé est dérivée de l'e-mail SAISI (existant ou non) : la réponse est donc la même pour un compte
        // inconnu, et un attaquant ne peut pas deviner quelles adresses sont inscrites.
        // consume(0) = « regarder sans compter » ; il ne reste plus de jeton après 5 échecs.
        $accountLimiter = $this->loginAccountLimiter->create(hash('sha256', $email));
        $peek = $accountLimiter->consume(0);
        if ($peek->getRemainingTokens() < 1) {
            $this->audit->record(AuditAction::LOGIN_BLOCKED, null, null, null, ['reason' => 'account_rate_limit'], $email);

            return $this->tooManyAttempts($peek->getRetryAfter());
        }

        $user = '' !== $email && !$tooLong ? $this->users->findOneByEmail($email) : null;

        // --- Vérification du mot de passe À TEMPS ÉGAL, que le compte existe ou non. ---
        // Sans cela, « compte inconnu » répondrait en 1 ms et « mauvais mot de passe » en 100 ms (Argon2) :
        // la durée de réponse trahirait l'existence du compte. On effectue donc un hachage factice équivalent.
        $valid = false;
        if (mb_strlen($password) > self::MAX_PASSWORD_LENGTH) {
            $valid = false;
        } elseif (null !== $user && $user->hasPassword()) {
            $valid = $this->hasher->isPasswordValid($user, $password);
        } else {
            $this->burnHashingTime($password);
        }

        if (!$valid || null === $user) {
            $accountLimiter->consume(1); // un échec de plus sur ce compte
            $this->audit->record(AuditAction::LOGIN_FAILED, null, null, null, ['reason' => 'bad_credentials'], $email);

            // MESSAGE UNIQUE pour « compte inconnu », « mot de passe faux » et « compte sans mot de passe (Google) ».
            return new FlowOutcome(FlowStep::Login, 'invalid_credentials');
        }

        // --- Mot de passe correct ---
        $accountLimiter->reset(); // les échecs précédents sont effacés

        // Mise à niveau transparente du hachage si les paramètres Argon2 ont été renforcés depuis son enregistrement.
        if ($this->hasher->needsRehash($user)) {
            $this->users->upgradePassword($user, $this->hasher->hashPassword($user, $password));
        }

        return $this->begin($user, 'password');
    }

    /** Parcours ouvert juste après une inscription réussie (l'identité est « prouvée » par la création du compte). */
    public function startAfterRegistration(User $user): FlowOutcome
    {
        return $this->begin($user, 'registration');
    }

    /** Parcours ouvert après une connexion Google/Apple validée (identité garantie par le fournisseur). */
    public function startAfterSocial(User $user, SocialProvider $provider): FlowOutcome
    {
        return $this->begin($user, $provider->value);
    }

    /**
     * Parcours « leurre » : quelqu'un s'est inscrit avec une adresse DÉJÀ utilisée. On affiche exactement le même
     * écran que pour une vraie inscription (« saisissez le code reçu »), mais aucun code n'existe : toute saisie
     * échoue. Le vrai titulaire a reçu un e-mail d'information (voir SecurityNotifier::alreadyRegistered).
     */
    public function startDecoy(string $email, string $fullName): FlowOutcome
    {
        // On mémorise le nom et l'e-mail SAISIS (pas ceux du vrai compte) : la réponse doit avoir exactement la même forme
        // que celle d'une vraie inscription, champ « user » compris (sinon son absence trahirait que l'adresse existe).
        $state = new FlowState('', 'registration', $this->now(), $this->now(), decoyTarget: Mask::email($email), decoyName: $fullName, decoyEmail: $email, decoySends: [$this->now()]);
        $this->save($state);

        return $this->decoyOutcome($state, $this->decoyResendIn($state));
    }

    /** Secondes avant le prochain « renvoi » du leurre : même délai de 30 s qu'un vrai code (app.otp.resend_cooldown). */
    private function decoyResendIn(FlowState $state): int
    {
        $last = [] === $state->decoySends ? $state->startedAt : max($state->decoySends);

        return max(0, 30 - ($this->now() - $last));
    }

    /**
     * Imite resend() pour un leurre : mêmes refus qu'un vrai compte (« trop tôt » avant 30 s, puis 3 envois maximum
     * par fenêtre de 10 minutes, comme le limiteur otp_send_user). Aucun code n'est évidemment envoyé.
     */
    private function decoyResend(FlowState $state): FlowOutcome
    {
        $now = $this->now();
        $state->decoySends = array_values(array_filter($state->decoySends, static fn (int $t): bool => $now - $t < 600));

        $wait = $this->decoyResendIn($state);
        if ($wait <= 0 && \count($state->decoySends) >= 3) {
            $wait = max(1, min($state->decoySends) + 600 - $now);
        }
        if ($wait > 0) {
            $this->save($state);

            return $this->decoyOutcome($state, $wait)->withError('too_soon', null, $wait);
        }

        $state->decoySends[] = $now;
        $this->save($state);

        return $this->decoyOutcome($state, $this->decoyResendIn($state));
    }

    /** Réponse « vérifiez votre e-mail » du leurre : mêmes champs qu'une vraie inscription (voir describe()). */
    private function decoyOutcome(FlowState $state, int $resendIn): FlowOutcome
    {
        return new FlowOutcome(
            FlowStep::VerifyEmail,
            null,
            $state->decoyTarget,
            OtpChannel::Email->value,
            [],
            $resendIn,
            0,
            null,
            false,
            null,
            ['name' => (string) $state->decoyName, 'email' => (string) $state->decoyEmail],
        );
    }

    // =========================================================================
    // ACTIONS : ce que l'utilisateur peut faire pendant le parcours
    // =========================================================================

    /** Où en est le parcours en cours (pour recharger la page sans perdre sa place). */
    public function resume(): FlowOutcome
    {
        $state = $this->state();
        if (null === $state) {
            return new FlowOutcome(FlowStep::Login);
        }
        if ($state->isDecoy()) {
            return $this->decoyOutcome($state, 0);
        }
        $user = $this->loadUser($state);
        if (null === $user) {
            return new FlowOutcome(FlowStep::Login, 'session_expired');
        }

        // On décrit l'étape SANS envoyer de code (aucun effet de bord sur un simple rechargement).
        return $this->describe($this->nextStep($state, $user), $state, $user, 0);
    }

    /** Vérifie le code saisi pour l'étape courante (e-mail, SMS ou 2FA). */
    public function verifyCode(string $code): FlowOutcome
    {
        $state = $this->state();
        if (null === $state) {
            return new FlowOutcome(FlowStep::Login, 'session_expired');
        }

        // Quota par IP sur les vérifications (en plus du plafond de 5 essais PAR code, en base).
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $limit = $this->otpVerifyIpLimiter->create($ip)->consume();
        if (!$limit->isAccepted()) {
            return $this->tooManyAttempts($limit->getRetryAfter());
        }

        // Leurre : tout code est faux, avec le même décompte d'essais que la réalité.
        if ($state->isDecoy()) {
            ++$state->decoyAttempts;
            $this->save($state);
            $left = 5 - $state->decoyAttempts;

            return $this->decoyOutcome($state, $this->decoyResendIn($state))->withError($left > 0 ? 'invalid_code' : 'code_locked', max(0, $left));
        }

        $user = $this->loadUser($state);
        if (null === $user) {
            return new FlowOutcome(FlowStep::Login, 'session_expired');
        }

        $step = $this->nextStep($state, $user);
        $now = $this->clock->now();

        switch ($step) {
            case FlowStep::VerifyEmail:
                $check = $this->otp->verify($user, OtpPurpose::EmailVerification, $user->getEmail(), $code);
                if (!$check->isValid()) {
                    return $this->codeError($check, $step, $state, $user);
                }
                $user->markEmailVerified($now);
                $user->touch($now);
                $this->em->flush();
                $this->audit->record(AuditAction::EMAIL_VERIFIED, $user, 'user', $user->getId()->toRfc4122());
                break;

            case FlowStep::VerifySms:
                $check = $this->otp->verify($user, OtpPurpose::PhoneVerification, (string) $user->getPhone(), $code);
                if (!$check->isValid()) {
                    return $this->codeError($check, $step, $state, $user);
                }
                if (!$this->phones->markVerified($user, $now)) {
                    // Ce numéro est déjà vérifié par un AUTRE compte. Un client peut continuer sans ; un fournisseur est bloqué.
                    $state->phoneSkipped = true;
                    $this->save($state);

                    return $this->describe($step, $state, $user, 0)->withError('phone_taken');
                }
                break;

            case FlowStep::TwoFactor:
                $channel = $this->twoFactorChannel($state, $user);
                $check = $this->otp->verify($user, OtpPurpose::TwoFactor, $this->targetFor($user, $channel), $code);
                if (!$check->isValid()) {
                    $this->audit->record(AuditAction::TWO_FACTOR_FAILED, $user, 'user', $user->getId()->toRfc4122());

                    return $this->codeError($check, $step, $state, $user);
                }
                $state->twoFactorDone = true;
                break;

            default:
                // Aucune saisie de code n'est attendue à cette étape (connexion, restauration…).
                return $this->describe($step, $state, $user, 0)->withError('unexpected_step');
        }

        return $this->advance($state, $user);
    }

    /**
     * Renvoie un code pour l'étape courante. Pour la 2FA, $channel permet de choisir « email » ou « sms »
     * (parmi les canaux réellement disponibles pour ce compte).
     */
    public function resend(?string $channel = null): FlowOutcome
    {
        $state = $this->state();
        if (null === $state) {
            return new FlowOutcome(FlowStep::Login, 'session_expired');
        }
        if ($state->isDecoy()) {
            // On simule un renvoi (même délai d'attente et mêmes plafonds qu'un vrai compte) : aucune différence visible.
            return $this->decoyResend($state);
        }
        $user = $this->loadUser($state);
        if (null === $user) {
            return new FlowOutcome(FlowStep::Login, 'session_expired');
        }

        $step = $this->nextStep($state, $user);

        // Changement de canal 2FA demandé : accepté seulement s'il fait partie des canaux autorisés du compte.
        if (FlowStep::TwoFactor === $step && null !== $channel) {
            $wanted = OtpChannel::tryFrom($channel);
            if (null !== $wanted && \in_array($wanted, $user->twoFactorChannels(), true)) {
                $state->channel = $wanted->value;
                $this->save($state);
            }
        }

        return $this->sendCodeForStep($step, $state, $user, true);
    }

    /** « Plus tard » : ignore la vérification FACULTATIVE du téléphone (clients uniquement, juste après l'inscription). */
    public function skip(): FlowOutcome
    {
        $state = $this->state();
        $user = null !== $state ? $this->loadUser($state) : null;
        if (null === $state || null === $user) {
            return new FlowOutcome(FlowStep::Login, 'session_expired');
        }

        // Seule l'étape SMS facultative peut être ignorée ; jamais celles qui conditionnent l'accès.
        if (FlowStep::VerifySms !== $this->nextStep($state, $user) || !$this->isPhoneStepOptional($state, $user)) {
            return $this->describe($this->nextStep($state, $user), $state, $user, 0)->withError('cannot_skip');
        }
        $state->phoneSkipped = true;

        return $this->advance($state, $user);
    }

    /** Le titulaire d'un compte « en cours de suppression » choisit de le restaurer. */
    public function confirmRestore(): FlowOutcome
    {
        $state = $this->state();
        $user = null !== $state ? $this->loadUser($state) : null;
        if (null === $state || null === $user) {
            return new FlowOutcome(FlowStep::Login, 'session_expired');
        }
        if (FlowStep::RestoreAccount !== $this->nextStep($state, $user)) {
            return $this->describe($this->nextStep($state, $user), $state, $user, 0)->withError('unexpected_step');
        }
        $state->restoreConfirmed = true;

        return $this->advance($state, $user);
    }

    /** Abandonne le parcours (bouton « Retour » / « Ce n'est pas moi »). */
    public function cancel(): void
    {
        $this->session()?->remove(FlowState::SESSION_KEY);
    }

    // =========================================================================
    // MOTEUR : calcul de l'étape suivante et passage à l'étape d'après
    // =========================================================================

    /**
     * Ouvre un parcours pour un utilisateur dont l'identité vient d'être prouvée, puis avance.
     */
    private function begin(User $user, string $method): FlowOutcome
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp();

        // Compte suspendu : refus clair. (L'identité est prouvée à ce stade, rien n'est donc divulgué.)
        if ($user->isSuspended()) {
            $this->audit->record(AuditAction::LOGIN_BLOCKED, $user, 'user', $user->getId()->toRfc4122(), ['reason' => 'suspended']);

            return new FlowOutcome(FlowStep::Blocked, 'account_suspended');
        }

        // Administrateur hors du réseau autorisé : refus, même avec le bon mot de passe.
        if ($user->isAdmin() && !$this->adminAccess->isIpAllowed($ip)) {
            $this->audit->record(AuditAction::ACCESS_DENIED, $user, 'user', $user->getId()->toRfc4122(), ['reason' => 'ip_not_allowed', 'ip' => $ip]);

            return new FlowOutcome(FlowStep::Blocked, 'network_not_allowed');
        }

        $now = $this->now();
        $state = new FlowState($user->getId()->toRfc4122(), $method, $now, $now);

        return $this->advance($state, $user);
    }

    /**
     * Calcule l'étape suivante ; si c'est « terminé », connecte l'utilisateur, sinon prépare l'étape (envoi du code).
     */
    private function advance(FlowState $state, User $user): FlowOutcome
    {
        $step = $this->nextStep($state, $user);
        if (FlowStep::Done === $step) {
            return $this->complete($state, $user);
        }

        $state->touchedAt = $this->now();
        $this->save($state);

        return $this->sendCodeForStep($step, $state, $user, false);
    }

    /**
     * LA règle d'accès, au même endroit : quelle est la prochaine étape à franchir pour cet utilisateur ?
     * Ordre : vérification des contacts → 2FA → restauration → terminé.
     */
    public function nextStep(FlowState $state, User $user): FlowStep
    {
        if ($user->isSuspended()) {
            return FlowStep::Blocked;
        }

        // ---- 1. Vérification des moyens de contact ----
        if ($user->isSupplier()) {
            // Fournisseur : e-mail ET téléphone, tous deux obligatoires.
            if (!$user->isEmailVerified()) {
                return FlowStep::VerifyEmail;
            }
            if (!$user->isPhoneVerified()) {
                return FlowStep::VerifySms;
            }
        } elseif ($user->isClient()) {
            // Client : e-mail OU téléphone vérifié suffit pour accéder au compte.
            if (!$user->hasVerifiedContact()) {
                return FlowStep::VerifyEmail;
            }
            // Après une inscription avec téléphone renseigné : on PROPOSE (sans l'imposer) de le vérifier.
            if ($this->isPhoneStepOptional($state, $user)) {
                return FlowStep::VerifySms;
            }
        }
        // Administrateur : l'e-mail est vérifié par l'ouverture du lien d'invitation (AdminAccountService).

        // ---- 2. Double authentification ----
        if ($user->isTwoFactorEnabled() && !$state->twoFactorDone) {
            return FlowStep::TwoFactor;
        }

        // ---- 3. Compte en cours de suppression : choix explicite de le restaurer ----
        if ($user->isPendingDeletion() && !$state->restoreConfirmed) {
            return FlowStep::RestoreAccount;
        }

        return FlowStep::Done;
    }

    /** La vérification du téléphone est-elle une étape FACULTATIVE à proposer maintenant ? */
    private function isPhoneStepOptional(FlowState $state, User $user): bool
    {
        return $user->isClient()
            && 'registration' === $state->method
            && null !== $user->getPhone()
            && !$user->isPhoneVerified()
            && !$state->phoneSkipped;
    }

    /**
     * Dernière étape : crée la session connectée.
     */
    private function complete(FlowState $state, User $user): FlowOutcome
    {
        $now = $this->clock->now();

        // Restauration d'un compte en cours de suppression (le titulaire l'a confirmé à l'étape précédente).
        if ($user->isPendingDeletion()) {
            $user->cancelDeletion();
            $this->audit->record(AuditAction::ACCOUNT_RESTORED, $user, 'user', $user->getId()->toRfc4122());
            $this->notifier->accountRestored($user);
        }

        $user->recordLogin($now);
        $user->touch($now);
        $this->em->flush();

        // On efface l'état du parcours AVANT de créer la session connectée.
        $session = $this->session();
        $session?->remove(FlowState::SESSION_KEY);

        // Création du jeton Symfony : migre l'identifiant de session (anti-fixation), applique le UserChecker.
        $this->security->login($user, FlowAuthenticator::class, 'main');

        $this->audit->record(AuditAction::LOGIN_SUCCESS, $user, 'user', $user->getId()->toRfc4122(), ['method' => $state->method]);

        $redirect = null !== $session ? $this->redirector->targetFor($user, $session) : '/';

        return new FlowOutcome(FlowStep::Done, redirect: $redirect, user: $this->userInfo($user));
    }

    // =========================================================================
    // ENVOI DES CODES ET DESCRIPTION DES ÉTAPES
    // =========================================================================

    /**
     * Prépare l'étape : envoie le code si l'étape en demande un.
     *
     * @param bool $force true = l'utilisateur a cliqué « renvoyer » (on renvoie même si un code est encore valide)
     */
    private function sendCodeForStep(FlowStep $step, FlowState $state, User $user, bool $force): FlowOutcome
    {
        [$purpose, $channel, $target] = match ($step) {
            FlowStep::VerifyEmail => [OtpPurpose::EmailVerification, OtpChannel::Email, $user->getEmail()],
            FlowStep::VerifySms => [OtpPurpose::PhoneVerification, OtpChannel::Sms, (string) $user->getPhone()],
            FlowStep::TwoFactor => [OtpPurpose::TwoFactor, $this->twoFactorChannel($state, $user), $this->targetFor($user, $this->twoFactorChannel($state, $user))],
            default => [null, null, null],
        };

        // Étape sans code (restauration, blocage, connexion).
        if (null === $purpose) {
            return $this->describe($step, $state, $user, 0);
        }

        // À l'ENTRÉE dans l'étape, on n'envoie un code que s'il n'y en a pas déjà un de valable
        // (recharger la page ne doit pas déclencher un nouveau SMS facturé).
        if (!$force && $this->otp->hasActiveCode($user, $purpose, $target)) {
            return $this->describe($step, $state, $user, $this->otp->secondsBeforeResend($user, $purpose));
        }

        try {
            $this->otp->issue($user, $purpose, $channel, $target);
        } catch (OtpSendRefusedException $e) {
            // Trop tôt / quota : pas une panne, l'interface affiche le compte à rebours.
            return $this->describe($step, $state, $user, $e->retryAfterSeconds)->withError('too_soon', null, $e->retryAfterSeconds);
        } catch (OtpDeliveryFailedException) {
            return $this->describe($step, $state, $user, 0)->withError('delivery_failed');
        }

        return $this->describe($step, $state, $user, $this->otp->secondsBeforeResend($user, $purpose));
    }

    /** Construit la réponse décrivant une étape (destination masquée, canaux, possibilité de passer…). */
    private function describe(FlowStep $step, FlowState $state, User $user, int $resendIn): FlowOutcome
    {
        $masked = null;
        $channel = null;
        $channels = [];
        $skippable = false;

        switch ($step) {
            case FlowStep::VerifyEmail:
                $masked = Mask::email($user->getEmail());
                $channel = OtpChannel::Email->value;
                break;
            case FlowStep::VerifySms:
                $masked = null !== $user->getPhone() ? PhoneNumber::fromE164($user->getPhone())->masked() : null;
                $channel = OtpChannel::Sms->value;
                $skippable = $this->isPhoneStepOptional($state, $user);
                break;
            case FlowStep::TwoFactor:
                $current = $this->twoFactorChannel($state, $user);
                $channel = $current->value;
                $masked = $this->maskedTargetFor($user, $current);
                $channels = array_map(static fn (OtpChannel $c): string => $c->value, $user->twoFactorChannels());
                break;
            default:
                break;
        }

        return new FlowOutcome($step, null, $masked, $channel, $channels, $resendIn, 0, null, $skippable, null, $this->userInfo($user));
    }

    /** Transforme le résultat d'une saisie de code fausse/périmée en réponse. */
    private function codeError(OtpCheck $check, FlowStep $step, FlowState $state, User $user): FlowOutcome
    {
        $base = $this->describe($step, $state, $user, $this->otp->secondsBeforeResend($user, match ($step) {
            FlowStep::VerifyEmail => OtpPurpose::EmailVerification,
            FlowStep::VerifySms => OtpPurpose::PhoneVerification,
            default => OtpPurpose::TwoFactor,
        }));

        return match ($check->result) {
            OtpResult::Invalid => $base->withError('invalid_code', $check->attemptsLeft),
            OtpResult::Locked => $base->withError('code_locked', 0),
            default => $base->withError('code_expired'),
        };
    }

    // =========================================================================
    // OUTILS INTERNES
    // =========================================================================

    /** Canal 2FA courant : celui choisi s'il est encore valable, sinon le premier disponible (e-mail avant SMS). */
    private function twoFactorChannel(FlowState $state, User $user): OtpChannel
    {
        $available = $user->twoFactorChannels();
        $chosen = null !== $state->channel ? OtpChannel::tryFrom($state->channel) : null;
        if (null !== $chosen && \in_array($chosen, $available, true)) {
            return $chosen;
        }

        // Un administrateur a toujours le canal e-mail ; pour les autres la liste n'est jamais vide quand la 2FA est active.
        return $available[0] ?? OtpChannel::Email;
    }

    private function targetFor(User $user, OtpChannel $channel): string
    {
        return OtpChannel::Sms === $channel ? (string) $user->getPhone() : $user->getEmail();
    }

    private function maskedTargetFor(User $user, OtpChannel $channel): ?string
    {
        if (OtpChannel::Sms === $channel) {
            return null !== $user->getPhone() ? PhoneNumber::fromE164($user->getPhone())->masked() : null;
        }

        return Mask::email($user->getEmail());
    }

    /** @return array<string, string> */
    private function userInfo(User $user): array
    {
        return ['name' => $user->getFullName(), 'email' => $user->getEmail()];
    }

    private function tooManyAttempts(\DateTimeImmutable $retryAt): FlowOutcome
    {
        return new FlowOutcome(FlowStep::Login, 'too_many_attempts', retryAfter: max(1, $retryAt->getTimestamp() - $this->now()));
    }

    /**
     * Gaspille le même temps qu'une vraie vérification Argon2 (voir attemptPassword).
     * Le résultat est volontairement ignoré.
     */
    private function burnHashingTime(string $password): void
    {
        try {
            $this->hasherFactory->getPasswordHasher(User::class)->hash($password);
        } catch (InvalidPasswordException) {
            // Mot de passe trop long pour Argon2 : sans importance, on a déjà plafonné la longueur plus haut.
        }
    }

    // --- Session ----------------------------------------------------------------

    private function session(): ?SessionInterface
    {
        return $this->requestStack->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession() : null;
    }

    private function state(): ?FlowState
    {
        $session = $this->session();
        $state = FlowState::fromArray($session?->get(FlowState::SESSION_KEY));
        if (null === $state) {
            return null;
        }

        // Parcours trop ancien ou abandonné depuis trop longtemps : on le détruit.
        $now = $this->now();
        if ($now - $state->startedAt > FlowState::MAX_AGE_SECONDS || $now - $state->touchedAt > FlowState::IDLE_SECONDS) {
            $session?->remove(FlowState::SESSION_KEY);

            return null;
        }

        return $state;
    }

    private function save(FlowState $state): void
    {
        $state->touchedAt = $this->now();
        $this->session()?->set(FlowState::SESSION_KEY, $state->toArray());
    }

    private function loadUser(FlowState $state): ?User
    {
        return $this->users->findOneByUuid($state->userId);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
