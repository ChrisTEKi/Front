<?php

namespace App\Account;

use App\Admin\AdminActionException;
use App\Admin\StepUpService;
use App\Auth\Exception\OtpDeliveryFailedException;
use App\Auth\Exception\OtpSendRefusedException;
use App\Auth\OtpResult;
use App\Auth\OtpService;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * « Prouvez que c'est bien vous » avant une action SENSIBLE sur son propre compte
 * (changer de mot de passe, de téléphone, désactiver la 2FA, supprimer le compte).
 *
 * Pourquoi : une session ouverte peut être utilisée par quelqu'un d'autre (téléphone prêté, ordinateur partagé, cookie volé).
 * Ces actions exigent donc une preuve SUPPLÉMENTAIRE, à l'instant même :
 *
 *   - compte AVEC mot de passe          → on redemande le mot de passe (5 essais ratés / 15 min, voir StepUpService) ;
 *   - compte SANS mot de passe (Google/Apple) → un code à 6 chiffres envoyé par e-mail à l'adresse du compte.
 *
 * Le code est lié à une finalité (suppression de compte ≠ réglage de sécurité) : un code ne sert jamais pour autre chose.
 */
final class Reauthenticator
{
    public function __construct(
        private readonly StepUpService $stepUp,
        private readonly OtpService $otp,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactoryInterface $otpVerifyIpLimiter,
    ) {
    }

    /**
     * Envoie le code de confirmation (comptes sans mot de passe uniquement).
     *
     * @return int secondes avant de pouvoir en redemander un
     *
     * @throws AccountActionException « code_not_needed », « too_soon », « delivery_failed »
     */
    public function sendCode(User $user, OtpPurpose $purpose): int
    {
        if ($user->hasPassword()) {
            throw new AccountActionException('code_not_needed');
        }

        try {
            $this->otp->issue($user, $purpose, OtpChannel::Email, $user->getEmail());
        } catch (OtpSendRefusedException $e) {
            throw new AccountActionException('too_soon', ['%seconds%' => $e->retryAfterSeconds]);
        } catch (OtpDeliveryFailedException) {
            throw new AccountActionException('delivery_failed');
        }

        return $this->otp->secondsBeforeResend($user, $purpose);
    }

    /**
     * Exige la preuve : mot de passe (si le compte en a un) ou code reçu par e-mail (sinon).
     *
     * @throws AccountActionException
     */
    public function require(User $user, string $password, string $code, OtpPurpose $purpose): void
    {
        if ($user->hasPassword()) {
            try {
                $this->stepUp->require($user, $password);
            } catch (AdminActionException $e) {
                // Mêmes raisons et mêmes paramètres : « step_up_failed », « step_up_locked ».
                throw new AccountActionException($e->reason, $e->params);
            }

            return;
        }

        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $limit = $this->otpVerifyIpLimiter->create($ip)->consume();
        if (!$limit->isAccepted()) {
            $wait = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

            throw new AccountActionException('too_many_attempts', ['%minutes%' => (int) ceil($wait / 60)]);
        }
        if ('' === trim($code)) {
            throw new AccountActionException('code_required');
        }

        $check = $this->otp->verify($user, $purpose, $user->getEmail(), $code);
        if (!$check->isValid()) {
            throw match ($check->result) {
                OtpResult::Invalid => new AccountActionException('invalid_code', ['%attempts%' => $check->attemptsLeft ?? 0]),
                OtpResult::Locked => new AccountActionException('code_locked'),
                default => new AccountActionException('code_expired'),
            };
        }
    }
}
