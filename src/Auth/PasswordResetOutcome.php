<?php

namespace App\Auth;

/**
 * Résultat d'une demande de réinitialisation du mot de passe (voir PasswordResetService::reset()).
 */
final class PasswordResetOutcome
{
    /**
     * @param array<string, list<string>> $fieldErrors Erreurs par champ (ex. mot de passe trop faible)
     */
    public function __construct(
        public readonly bool $ok,
        /** Clé d'erreur générale (domaine « auth », préfixe « error. ») : « invalid_code », « too_many_attempts »… */
        public readonly ?string $error = null,
        public readonly array $fieldErrors = [],
        public readonly int $retryAfter = 0,
    ) {
    }
}
