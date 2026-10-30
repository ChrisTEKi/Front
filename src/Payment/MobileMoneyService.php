<?php

namespace App\Payment;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Auth\Exception\OtpDeliveryFailedException;
use App\Auth\Exception\OtpSendRefusedException;
use App\Auth\OtpResult;
use App\Auth\OtpService;
use App\Auth\VerificationResult;
use App\Entity\MobileMoneyAccount;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Phone\MobileMoneyNumberValidator;
use App\Phone\MobileMoneyValidation;
use App\Repository\MobileMoneyAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Vérification d'un numéro Mobile Money AVANT de payer avec.
 *
 * Pourquoi : sans cette étape, quelqu'un pourrait saisir le numéro d'un tiers à la caisse (erreur de frappe ou fraude),
 * et la demande de paiement irait sur le portefeuille de cette personne. On exige donc que le client PROUVE qu'il
 * contrôle la ligne : il reçoit un code par SMS sur ce numéro et le saisit.
 *
 * Parcours :
 *   1) start()   : le numéro est contrôlé (mêmes règles que la maquette : 034/038 MVola, 033 Airtel, 032/037 Orange),
 *                  puis ...
 *                    - déjà vérifié pour ce client                     → rien à faire ;
 *                    - identique à SON téléphone déjà vérifié par SMS   → reconnu directement (preuve déjà faite) ;
 *                    - sinon                                            → un code SMS part vers ce numéro.
 *   2) confirm() : le code est contrôlé ; en cas de succès le numéro est enregistré comme vérifié (table
 *                  `mobile_money_accounts`) et ne sera plus redemandé pour ce client.
 *
 * Seuls les numéros VÉRIFIÉS sont enregistrés en base : un numéro refusé ou abandonné ne laisse aucune trace
 * (pas de lignes inutiles, rien à nettoyer).
 *
 * LIMITE À CONNAÎTRE : ceci prouve que le client possède la ligne mobile. Le débit réel du portefeuille et la
 * confirmation par code PIN sont faits par l'opérateur (MVola, Orange Money, Airtel Money) via la passerelle de paiement.
 */
final class MobileMoneyService
{
    /** Nombre maximal de numéros Mobile Money mémorisés par client (évite l'accumulation de lignes via des essais en série). */
    public const MAX_NUMBERS_PER_USER = 5;

    public function __construct(
        private readonly MobileMoneyNumberValidator $validator,
        private readonly MobileMoneyAccountRepository $accounts,
        private readonly EntityManagerInterface $em,
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactoryInterface $otpVerifyIpLimiter,
    ) {
    }

    /**
     * Étape 1 : prépare l'utilisation d'un numéro (envoie un code SMS si nécessaire).
     */
    public function start(User $user, string $raw): VerificationResult
    {
        $check = $this->validator->validate($raw);
        if (!$check->valid || null === $check->carrier || null === $check->phone) {
            return VerificationResult::failed('mobile_money_invalid', messageKey: $check->messageKey);
        }
        $msisdn = $check->phone->e164;
        $info = ['carrier' => $check->carrier->value, 'number' => $check->phone->masked()];

        // Déjà vérifié auparavant pour ce client, ou c'est SON téléphone déjà prouvé par SMS : rien à envoyer.
        if (null !== $this->ensureVerified($user, $check)) {
            return VerificationResult::verified($info);
        }

        // Plafond de numéros mémorisés.
        if ($this->accounts->count(['user' => $user]) >= self::MAX_NUMBERS_PER_USER) {
            return VerificationResult::failed('too_many_numbers');
        }

        try {
            $this->otp->issue($user, OtpPurpose::PaymentPhone, OtpChannel::Sms, $msisdn);
        } catch (OtpSendRefusedException $e) {
            return VerificationResult::failed('too_soon', $check->phone->masked(), $e->retryAfterSeconds, $e->retryAfterSeconds, data: $info);
        } catch (OtpDeliveryFailedException) {
            return VerificationResult::failed('delivery_failed', $check->phone->masked(), data: $info);
        }

        return VerificationResult::sent($check->phone->masked(), $this->otp->secondsBeforeResend($user, OtpPurpose::PaymentPhone));
    }

    /**
     * Étape 2 : contrôle le code reçu par SMS ; en cas de succès le numéro est mémorisé comme vérifié.
     */
    public function confirm(User $user, string $raw, string $code): VerificationResult
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $limit = $this->otpVerifyIpLimiter->create($ip)->consume();
        if (!$limit->isAccepted()) {
            $wait = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

            return VerificationResult::failed('too_many_attempts', retryAfter: $wait);
        }

        $check = $this->validator->validate($raw);
        if (!$check->valid || null === $check->carrier || null === $check->phone) {
            return VerificationResult::failed('mobile_money_invalid', messageKey: $check->messageKey);
        }
        $msisdn = $check->phone->e164;
        $masked = $check->phone->masked();
        $info = ['carrier' => $check->carrier->value, 'number' => $masked];

        $result = $this->otp->verify($user, OtpPurpose::PaymentPhone, $msisdn, $code);
        if (!$result->isValid()) {
            return match ($result->result) {
                OtpResult::Invalid => VerificationResult::failed('invalid_code', $masked, attemptsLeft: $result->attemptsLeft, data: $info),
                OtpResult::Locked => VerificationResult::failed('code_locked', $masked, attemptsLeft: 0, data: $info),
                default => VerificationResult::failed('code_expired', $masked, data: $info),
            };
        }

        $this->remember($user, $check, $this->clock->now());

        return VerificationResult::verified($info);
    }

    /** Le numéro (au format E.164) est-il un numéro Mobile Money VÉRIFIÉ de ce client ? */
    public function verifiedAccount(User $user, string $msisdn): ?MobileMoneyAccount
    {
        $account = $this->accounts->findOneByUserAndMsisdn($user, $msisdn);

        return null !== $account && $account->isVerified() ? $account : null;
    }

    /**
     * Retourne le compte Mobile Money VÉRIFIÉ correspondant à ce numéro, ou null s'il reste à vérifier par SMS.
     *
     * Deux cas donnent « vérifié » sans nouveau SMS :
     *   - le client a déjà vérifié ce numéro (ligne `mobile_money_accounts` avec verified_at) ;
     *   - c'est SON téléphone de compte, déjà prouvé par SMS (la preuve de possession existe : on la réutilise).
     *
     * C'est aussi la fonction qu'utilise OrderService pour refuser un paiement sur un numéro non vérifié.
     */
    public function ensureVerified(User $user, MobileMoneyValidation $check): ?MobileMoneyAccount
    {
        if (!$check->valid || null === $check->phone) {
            return null;
        }
        $msisdn = $check->phone->e164;

        $account = $this->verifiedAccount($user, $msisdn);
        if (null !== $account) {
            return $account;
        }

        if ($user->isPhoneVerified() && $user->getPhone() === $msisdn) {
            $this->remember($user, $check, $this->clock->now());

            return $this->verifiedAccount($user, $msisdn);
        }

        return null;
    }

    /** Enregistre (ou complète) le numéro comme vérifié, et journalise. */
    private function remember(User $user, MobileMoneyValidation $check, \DateTimeImmutable $now): void
    {
        \assert(null !== $check->carrier && null !== $check->phone);
        $msisdn = $check->phone->e164;

        $account = $this->accounts->findOneByUserAndMsisdn($user, $msisdn);
        if (null === $account) {
            $account = new MobileMoneyAccount($user, $check->carrier, $msisdn, $now);
            $this->em->persist($account);
        }
        $account->markVerified($now);

        // NB : si deux requêtes simultanées insèrent le même numéro, la contrainte unique (user_id, msisdn) protège la base
        // et la seconde reçoit une erreur : le navigateur réessaie et trouve le numéro déjà enregistré.
        $this->em->flush();

        $this->audit->record(AuditAction::MOBILE_MONEY_VERIFIED, $user, 'user', $user->getId()->toRfc4122(), [
            'carrier' => $check->carrier->value,
            // Numéro masqué : le journal d'audit ne doit pas devenir un annuaire de numéros.
            'number' => $check->phone->masked(),
        ]);
    }
}
