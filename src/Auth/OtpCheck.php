<?php

namespace App\Auth;

/**
 * Résultat détaillé d'une vérification OTP : l'issue + le nombre d'essais restants (pour l'afficher à l'utilisateur).
 */
final class OtpCheck
{
    public function __construct(
        public readonly OtpResult $result,
        /** Essais restants sur le code courant (renseigné pour Invalid, sinon null). */
        public readonly ?int $attemptsLeft = null,
    ) {
    }

    public function isValid(): bool
    {
        return OtpResult::Valid === $this->result;
    }
}
