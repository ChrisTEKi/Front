<?php

namespace App\Account;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Auth\Exception\OtpDeliveryFailedException;
use App\Auth\Exception\OtpSendRefusedException;
use App\Auth\Mask;
use App\Auth\OtpResult;
use App\Auth\OtpService;
use App\Auth\PhoneVerifier;
use App\Auth\VerificationResult;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Notification\SecurityNotifier;
use App\Phone\PhoneNumber;
use App\Phone\PhoneNumberParser;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Changer son ADRESSE E-MAIL ou son NUMÉRO DE TÉLÉPHONE, avec double confirmation — pour tous les types de compte
 * (client, fournisseur, administrateur, Super Admin).
 *
 * Pourquoi une double confirmation : l'e-mail est l'identifiant de connexion ET l'endroit où arrivent les codes de
 * récupération. Quiconque en prend le contrôle prend le compte. Une session ouverte (téléphone prêté, cookie volé) ne suffit donc pas.
 *
 *   ÉTAPE 1  — la personne indique la nouvelle valeur ; un code part vers l'ANCIENNE adresse e-mail (preuve : « je suis bien le
 *              titulaire de ce compte ») ;
 *   ÉTAPE 2  — une fois ce code saisi, un second code part vers la NOUVELLE adresse e-mail (ou, pour un numéro, un SMS au
 *              nouveau numéro) : preuve que la nouvelle destination existe et appartient bien à la personne ;
 *   ÉTAPE 3  — seulement après le second code juste, le compte est modifié. Rien n'est écrit avant.
 *
 * Retirer son numéro de téléphone n'a pas de nouvelle destination : seul le code de l'ancienne adresse est demandé.
 *
 * ANTI-ÉNUMÉRATION : si la nouvelle adresse appartient DÉJÀ à un autre compte, on ne le dit jamais (sinon un compte jetable
 * servirait à tester des adresses). L'écran est identique ; aucun code n'est envoyé à cette adresse, mais son vrai titulaire
 * reçoit un avis (limité à 3 par jour). Les codes saisis sont refusés comme de simples codes faux (voir « leurre »).
 *
 * Les AUTRES sessions du compte sont fermées après un changement d'e-mail (l'empreinte de session contient l'e-mail,
 * voir User::isEqualTo) ; la session en cours est rouverte par le contrôleur.
 *
 * L'état de la demande vit dans la session du serveur (voir ContactChangeState).
 */
final class ContactChangeService
{
    public const SESSION_KEY = 'gobuy.contact_change';

    /** Durée de vie d'une demande de changement : 30 minutes. */
    private const TTL_SECONDS = 1800;
    private const RESEND_COOLDOWN_SECONDS = 30;
    private const DECOY_MAX_SENDS = 3;
    private const MAX_EMAIL_LENGTH = 180;

    public function __construct(
        private readonly OtpService $otp,
        private readonly PhoneNumberParser $phoneParser,
        private readonly UserRepository $users,
        private readonly PhoneVerifier $phoneVerifier,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly SecurityNotifier $notifier,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactoryInterface $otpVerifyIpLimiter,
        private readonly RateLimiterFactoryInterface $contactNewTargetLimiter,
        private readonly RateLimiterFactoryInterface $contactChangeNoticeLimiter,
    ) {
    }

    // =========================================================================
    // ÉTAPE 1 — la demande
    // =========================================================================

    /**
     * Enregistre la demande et envoie le code à l'ANCIENNE adresse e-mail.
     *
     * @param string $kind  « email » ou « phone »
     * @param string $value nouvelle adresse / nouveau numéro ; vide pour « phone » = retirer le numéro
     */
    public function start(User $user, string $kind, string $value): VerificationResult
    {
        $value = trim($value);

        if ('email' === $kind) {
            $target = mb_strtolower($value);
            if ('' === $target) {
                return VerificationResult::failed('email_required');
            }
            if (mb_strlen($target) > self::MAX_EMAIL_LENGTH || $this->validator->validate($target, [new Assert\Email()])->count() > 0) {
                return VerificationResult::failed('invalid_email');
            }
            if ($target === $user->getEmail()) {
                return VerificationResult::failed('same_email');
            }
        } elseif ('phone' === $kind) {
            if ('' === $value) {
                if (null === $user->getPhone()) {
                    return VerificationResult::failed('phone_required');
                }
                // Un fournisseur doit rester joignable : son numéro vérifié ne se retire pas (il se remplace).
                if ($user->isSupplier()) {
                    return VerificationResult::failed('supplier_phone_required');
                }
                $target = '';
            } else {
                $number = $this->phoneParser->parse($value);
                if (null === $number) {
                    return VerificationResult::failed('invalid_phone');
                }
                if ($number->e164 === $user->getPhone() && $user->isPhoneVerified()) {
                    return VerificationResult::failed('same_phone');
                }
                $target = $number->e164;
            }
        } else {
            return VerificationResult::failed('invalid_request');
        }

        $now = $this->clock->now()->getTimestamp();
        $state = new ContactChangeState($user->getId()->toRfc4122(), $kind, $target, $user->getEmail(), ContactChangeState::STEP_OLD, $now);

        // Une demande déjà en cours dont le code de l'ancienne adresse est encore valable : on change seulement la valeur
        // demandée (faute de frappe corrigée) SANS renvoyer d'e-mail — pas d'envoi inutile, pas de délai d'attente pour la personne.
        $previous = $this->state($user);
        if (null !== $previous && ContactChangeState::STEP_OLD === $previous->step && $this->otp->hasActiveCode($user, OtpPurpose::ContactChangeOld, $user->getEmail())) {
            $previous->kind = $state->kind;
            $previous->target = $state->target;
            $this->save($previous);

            return $this->sentOld($user, $previous);
        }

        try {
            $this->otp->issue($user, OtpPurpose::ContactChangeOld, OtpChannel::Email, $user->getEmail());
        } catch (OtpSendRefusedException $e) {
            return VerificationResult::failed('too_soon', Mask::email($user->getEmail()), $e->retryAfterSeconds, $e->retryAfterSeconds);
        } catch (OtpDeliveryFailedException) {
            return VerificationResult::failed('delivery_failed', Mask::email($user->getEmail()));
        }

        $this->save($state);

        return $this->sentOld($user, $state);
    }

    // =========================================================================
    // ÉTAPES 2 et 3 — le code saisi
    // =========================================================================

    /**
     * Contrôle le code saisi pour l'étape en cours : ancienne adresse → on envoie le second code ; nouvelle destination → on applique.
     *
     * @return VerificationResult « sent » (étape suivante, avec `step` = new) ou « verified » (changement appliqué, avec `kind`)
     */
    public function confirm(User $user, string $code): VerificationResult
    {
        $state = $this->state($user);
        if (null === $state) {
            return VerificationResult::failed('contact_change_expired');
        }

        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $limit = $this->otpVerifyIpLimiter->create($ip)->consume();
        if (!$limit->isAccepted()) {
            return VerificationResult::failed('too_many_attempts', retryAfter: max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()));
        }

        return ContactChangeState::STEP_OLD === $state->step ? $this->confirmOld($user, $state, $code) : $this->confirmNew($user, $state, $code);
    }

    private function confirmOld(User $user, ContactChangeState $state, string $code): VerificationResult
    {
        $masked = Mask::email($user->getEmail());
        $check = $this->otp->verify($user, OtpPurpose::ContactChangeOld, $user->getEmail(), $code);
        if (!$check->isValid()) {
            return $this->codeFailure($check->result, $check->attemptsLeft, $masked);
        }

        // Retrait du numéro : il n'y a pas de nouvelle destination à confirmer, l'ancienne adresse suffit.
        if ($state->isRemoval()) {
            return $this->applyPhone($user, $state);
        }

        return $this->enterNewStep($user, $state);
    }

    private function confirmNew(User $user, ContactChangeState $state, string $code): VerificationResult
    {
        $masked = $this->maskedTarget($state);

        // Leurre : la nouvelle adresse appartient à un autre compte, aucun code n'existe. On répond comme pour un code faux.
        if ($state->decoy) {
            if ($state->decoyAttempts <= 0) {
                return VerificationResult::failed('code_locked', $masked, attemptsLeft: 0);
            }
            --$state->decoyAttempts;
            $this->save($state);

            return $state->decoyAttempts <= 0
                ? VerificationResult::failed('code_locked', $masked, attemptsLeft: 0)
                : VerificationResult::failed('invalid_code', $masked, attemptsLeft: $state->decoyAttempts);
        }

        $check = $this->otp->verify($user, OtpPurpose::ContactChangeNew, $state->target, $code);
        if (!$check->isValid()) {
            return $this->codeFailure($check->result, $check->attemptsLeft, $masked);
        }

        return 'email' === $state->kind ? $this->applyEmail($user, $state) : $this->applyPhone($user, $state);
    }

    /** Ancienne adresse confirmée : on passe à la nouvelle destination (code réel, ou leurre si l'adresse est déjà prise). */
    private function enterNewStep(User $user, ContactChangeState $state): VerificationResult
    {
        $state->step = ContactChangeState::STEP_NEW;
        $state->decoy = false;

        // Quota par destination : au plus 5 codes par jour vers une même adresse, quel que soit le compte demandeur. La même limite
        // s'applique au leurre, pour que rien ne distingue les deux cas.
        if ('email' === $state->kind) {
            $limit = $this->contactNewTargetLimiter->create(hash('sha256', $state->target))->consume();
            if (!$limit->isAccepted()) {
                $this->save($state);
                $wait = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

                return VerificationResult::failed('too_soon', $this->maskedTarget($state), $wait, $wait);
            }

            $owner = $this->users->findOneByEmail($state->target);
            if (null !== $owner && !$owner->getId()->equals($user->getId())) {
                $state->decoy = true;
                $state->decoyAttempts = 5;
                $state->decoySends = 1;
                $state->decoyLastSentAt = $this->clock->now()->getTimestamp();
                $this->save($state);
                $this->warnOwner($owner);

                return $this->sentNew($state, self::RESEND_COOLDOWN_SECONDS);
            }
        }

        $failure = $this->issueNew($user, $state);
        $this->save($state);

        return $failure ?? $this->sentNew($state, $this->otp->secondsBeforeResend($user, OtpPurpose::ContactChangeNew));
    }

    // =========================================================================
    // Renvoi / annulation
    // =========================================================================

    /** Renvoie le code de l'étape en cours (mêmes délais et quotas qu'un premier envoi). */
    public function resend(User $user): VerificationResult
    {
        $state = $this->state($user);
        if (null === $state) {
            return VerificationResult::failed('contact_change_expired');
        }

        if (ContactChangeState::STEP_OLD === $state->step) {
            try {
                $this->otp->issue($user, OtpPurpose::ContactChangeOld, OtpChannel::Email, $user->getEmail());
            } catch (OtpSendRefusedException $e) {
                return VerificationResult::failed('too_soon', Mask::email($user->getEmail()), $e->retryAfterSeconds, $e->retryAfterSeconds);
            } catch (OtpDeliveryFailedException) {
                return VerificationResult::failed('delivery_failed', Mask::email($user->getEmail()));
            }

            return $this->sentOld($user, $state);
        }

        if ($state->decoy) {
            // Imite le délai de 30 s et le plafond d'envois d'un vrai code.
            $now = $this->clock->now()->getTimestamp();
            $elapsed = $now - $state->decoyLastSentAt;
            if ($elapsed < self::RESEND_COOLDOWN_SECONDS) {
                $wait = self::RESEND_COOLDOWN_SECONDS - $elapsed;

                return VerificationResult::failed('too_soon', $this->maskedTarget($state), $wait, $wait);
            }
            if ($state->decoySends >= self::DECOY_MAX_SENDS) {
                return VerificationResult::failed('too_soon', $this->maskedTarget($state), 600, 600);
            }
            ++$state->decoySends;
            $state->decoyLastSentAt = $now;
            $state->decoyAttempts = 5;
            $this->save($state);

            return $this->sentNew($state, self::RESEND_COOLDOWN_SECONDS);
        }

        $failure = $this->issueNew($user, $state);

        return $failure ?? $this->sentNew($state, $this->otp->secondsBeforeResend($user, OtpPurpose::ContactChangeNew));
    }

    /** Abandonne la demande en cours. */
    public function cancel(): void
    {
        $this->session()?->remove(self::SESSION_KEY);
    }

    // =========================================================================
    // Application du changement (seulement après les deux codes)
    // =========================================================================

    private function applyEmail(User $user, ContactChangeState $state): VerificationResult
    {
        $old = $user->getEmail();
        $new = $state->target;
        $this->cancel();

        $user->changeEmail($new, $this->clock->now());
        $user->touch($this->clock->now());
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Course : un compte vient d'être créé avec cette adresse entre-temps. L'index unique de la base tranche.
            // (L'EntityManager est fermé après cette exception : on ne touche plus à la base.)
            return VerificationResult::failed('contact_change_failed');
        }

        $this->audit->record(AuditAction::EMAIL_CHANGED, $user, 'user', $user->getId()->toRfc4122(), [
            'from' => Mask::email($old),
            'to' => Mask::email($new),
        ]);
        // L'avis part vers l'ANCIENNE adresse : c'est là que le vrai titulaire verrait passer un changement frauduleux.
        $this->notifier->emailChanged($old, $user->getFullName(), $user->getLocale(), Mask::email($new));

        return VerificationResult::verified(['kind' => 'email']);
    }

    private function applyPhone(User $user, ContactChangeState $state): VerificationResult
    {
        $this->cancel();
        $before = $user->getPhone();

        // Retrait du numéro.
        if ($state->isRemoval()) {
            if ($user->isSupplier()) {
                return VerificationResult::failed('supplier_phone_required');
            }
            $user->changePhone(null);
            $user->touch($this->clock->now());
            $this->em->flush();
            $this->audit->record(AuditAction::PHONE_CHANGED, $user, 'user', $user->getId()->toRfc4122(), ['removed' => true]);
            $this->notifier->phoneChanged($user);

            return VerificationResult::verified(['kind' => 'phone', 'phone' => null]);
        }

        // Un numéro vérifié n'appartient qu'à UN compte (voir PhoneVerifier). On le contrôle AVANT de toucher au compte.
        $other = $this->users->findOneByVerifiedPhone($state->target);
        if (null !== $other && !$other->getId()->equals($user->getId())) {
            return VerificationResult::failed('phone_taken');
        }

        // Le nouveau numéro vient d'être prouvé par SMS : la 2FA par SMS, si elle était active, peut rester active.
        $hadSmsTwoFactor = $user->isTwoFactorSmsEnabled();
        $user->changePhone($state->target);
        if (!$this->phoneVerifier->markVerified($user, $this->clock->now())) {
            return VerificationResult::failed('phone_taken');
        }
        if ($hadSmsTwoFactor) {
            $user->setTwoFactor($user->isTwoFactorEmailEnabled(), true);
            $this->em->flush();
        }

        if ($before !== $user->getPhone()) {
            $this->audit->record(AuditAction::PHONE_CHANGED, $user, 'user', $user->getId()->toRfc4122(), ['removed' => false, 'via' => 'double_confirmation']);
            $this->notifier->phoneChanged($user);
        }

        return VerificationResult::verified(['kind' => 'phone', 'phone' => PhoneNumber::fromE164($state->target)->masked()]);
    }

    // =========================================================================
    // Outils internes
    // =========================================================================

    /** Envoie le code de la NOUVELLE destination (e-mail, ou SMS pour un numéro). Retourne un échec, ou null si tout va bien. */
    private function issueNew(User $user, ContactChangeState $state): ?VerificationResult
    {
        $channel = 'email' === $state->kind ? OtpChannel::Email : OtpChannel::Sms;
        try {
            $this->otp->issue($user, OtpPurpose::ContactChangeNew, $channel, $state->target);
        } catch (OtpSendRefusedException $e) {
            return VerificationResult::failed('too_soon', $this->maskedTarget($state), $e->retryAfterSeconds, $e->retryAfterSeconds);
        } catch (OtpDeliveryFailedException) {
            return VerificationResult::failed('delivery_failed', $this->maskedTarget($state));
        }

        return null;
    }

    /** Prévient le vrai titulaire de l'adresse demandée (3 avis / jour au plus, silencieux au-delà). */
    private function warnOwner(User $owner): void
    {
        if ($this->contactChangeNoticeLimiter->create($owner->getId()->toRfc4122())->consume()->isAccepted()) {
            $this->notifier->emailAddressInUse($owner);
        }
    }

    private function sentOld(User $user, ContactChangeState $state): VerificationResult
    {
        return VerificationResult::sent(Mask::email($user->getEmail()), $this->otp->secondsBeforeResend($user, OtpPurpose::ContactChangeOld), ['step' => ContactChangeState::STEP_OLD, 'kind' => $state->kind]);
    }

    private function sentNew(ContactChangeState $state, int $resendIn): VerificationResult
    {
        return VerificationResult::sent($this->maskedTarget($state), $resendIn, ['step' => ContactChangeState::STEP_NEW, 'kind' => $state->kind]);
    }

    private function maskedTarget(ContactChangeState $state): string
    {
        return 'email' === $state->kind ? Mask::email($state->target) : PhoneNumber::fromE164($state->target)->masked();
    }

    private function codeFailure(OtpResult $result, ?int $attemptsLeft, string $masked): VerificationResult
    {
        return match ($result) {
            OtpResult::Invalid => VerificationResult::failed('invalid_code', $masked, attemptsLeft: $attemptsLeft),
            OtpResult::Locked => VerificationResult::failed('code_locked', $masked, attemptsLeft: 0),
            default => VerificationResult::failed('code_expired', $masked),
        };
    }

    /** Demande en cours de CE compte, encore valable (30 min, e-mail inchangé), sinon null. */
    private function state(User $user): ?ContactChangeState
    {
        $session = $this->session();
        $state = ContactChangeState::fromSession($session?->get(self::SESSION_KEY));
        if (null === $state) {
            return null;
        }
        if ($state->userId !== $user->getId()->toRfc4122() || $state->fromEmail !== $user->getEmail() || $this->clock->now()->getTimestamp() - $state->startedAt > self::TTL_SECONDS) {
            $session?->remove(self::SESSION_KEY);

            return null;
        }

        return $state;
    }

    private function save(ContactChangeState $state): void
    {
        $this->session()?->set(self::SESSION_KEY, $state->toArray());
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request && $request->hasSession() ? $request->getSession() : null;
    }
}
