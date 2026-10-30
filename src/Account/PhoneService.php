<?php

namespace App\Account;

use App\Auth\Exception\OtpDeliveryFailedException;
use App\Auth\Exception\OtpSendRefusedException;
use App\Auth\OtpResult;
use App\Auth\OtpService;
use App\Auth\PhoneVerifier;
use App\Auth\VerificationResult;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Phone\PhoneNumber;
use App\Phone\PhoneNumberParser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Renseigner puis vérifier SON téléphone quand on est DÉJÀ connecté — typiquement au moment de la 1re commande.
 *
 * Rappel de la règle métier :
 *   - à l'inscription, le téléphone est facultatif (et sa vérification aussi) ;
 *   - mais un téléphone mobile VALIDE ET VÉRIFIÉ par SMS est OBLIGATOIRE avant de passer une commande
 *     (le livreur et le suivi de colis en dépendent). OrderService le refuse sinon.
 *
 * Ce service permet donc, depuis la page de commande : « je saisis mon numéro (ou je confirme celui de mon profil),
 * je reçois un code par SMS, je le saisis ».
 *
 * Il réutilise OtpService (mêmes protections que partout : code haché, 5 essais, expiration, délai entre envois,
 * quotas SMS) et PhoneVerifier (règle « un numéro vérifié = un seul compte »).
 */
final class PhoneService
{
    public function __construct(
        private readonly PhoneNumberParser $parser,
        private readonly OtpService $otp,
        private readonly PhoneVerifier $verifier,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactoryInterface $otpVerifyIpLimiter,
    ) {
    }

    /**
     * Étape 1 : enregistre le numéro saisi sur le compte (non vérifié) et envoie le code SMS.
     *
     * @param string|null $raw Numéro saisi (n'importe quel format accepté) ; vide = utiliser celui déjà enregistré
     */
    public function start(User $user, ?string $raw): VerificationResult
    {
        $raw = null === $raw ? '' : trim($raw);

        // Quel numéro ? Celui qui vient d'être saisi, sinon celui déjà présent sur le compte.
        if ('' === $raw) {
            if (null === $user->getPhone()) {
                return VerificationResult::failed('phone_required');
            }
            $number = PhoneNumber::fromE164($user->getPhone());
        } else {
            $number = $this->parser->parse($raw);
            if (null === $number) {
                return VerificationResult::failed('invalid_phone');
            }
        }

        // Déjà vérifié : rien à envoyer.
        if ($user->isPhoneVerified() && $user->getPhone() === $number->e164) {
            return VerificationResult::verified(['phone' => $number->masked()]);
        }

        // Un numéro DÉJÀ vérifié ne se remplace pas depuis la page de commande : ce changement sensible
        // (il coupe la 2FA par SMS) passe par les réglages du compte, avec confirmation du mot de passe.
        if ($user->isPhoneVerified()) {
            return VerificationResult::failed('phone_change_forbidden');
        }

        // Enregistre le numéro (la vérification précédente éventuelle est annulée par changePhone()).
        if ($user->getPhone() !== $number->e164) {
            $user->changePhone($number->e164);
            $user->touch($this->clock->now());
            $this->em->flush();
        }

        try {
            $this->otp->issue($user, OtpPurpose::PhoneVerification, OtpChannel::Sms, $number->e164);
        } catch (OtpSendRefusedException $e) {
            return VerificationResult::failed('too_soon', $number->masked(), $e->retryAfterSeconds, $e->retryAfterSeconds);
        } catch (OtpDeliveryFailedException) {
            return VerificationResult::failed('delivery_failed', $number->masked());
        }

        return VerificationResult::sent($number->masked(), $this->otp->secondsBeforeResend($user, OtpPurpose::PhoneVerification));
    }

    /** Étape 2 : contrôle le code reçu par SMS et marque le téléphone comme vérifié. */
    public function confirm(User $user, string $code): VerificationResult
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $limit = $this->otpVerifyIpLimiter->create($ip)->consume();
        if (!$limit->isAccepted()) {
            $wait = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

            return VerificationResult::failed('too_many_attempts', retryAfter: $wait);
        }

        $phone = $user->getPhone();
        if (null === $phone) {
            return VerificationResult::failed('phone_required');
        }
        $masked = PhoneNumber::fromE164($phone)->masked();

        $check = $this->otp->verify($user, OtpPurpose::PhoneVerification, $phone, $code);
        if (!$check->isValid()) {
            return match ($check->result) {
                OtpResult::Invalid => VerificationResult::failed('invalid_code', $masked, attemptsLeft: $check->attemptsLeft),
                OtpResult::Locked => VerificationResult::failed('code_locked', $masked, attemptsLeft: 0),
                default => VerificationResult::failed('code_expired', $masked),
            };
        }

        // Code juste. Reste la règle « un numéro vérifié = un seul compte ».
        if (!$this->verifier->markVerified($user, $this->clock->now())) {
            return VerificationResult::failed('phone_taken', $masked);
        }

        return VerificationResult::verified(['phone' => $masked]);
    }
}
