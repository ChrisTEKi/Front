<?php

namespace App\Auth\Exception;

/**
 * L'envoi d'un code OTP est REFUSÉ (trop tôt après le précédent, ou quota dépassé).
 *
 * Le contrôleur la transforme en réponse HTTP 429 « Too Many Requests » avec le délai d'attente,
 * que l'interface affiche (« Renvoyer le code dans 24 s »).
 */
final class OtpSendRefusedException extends \RuntimeException
{
    public function __construct(
        /** Secondes à attendre avant de pouvoir redemander un code. */
        public readonly int $retryAfterSeconds,
        /** « cooldown » (délai minimal entre deux envois) ou « quota » (trop d'envois sur la période). */
        public readonly string $reason,
    ) {
        parent::__construct(sprintf('Envoi de code refusé (%s), réessayer dans %d s.', $reason, $retryAfterSeconds));
    }
}
