<?php

namespace App\Enum;

/**
 * Fournisseurs d'identité pour l'inscription/connexion rapide (colonne `social_identities.provider`).
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Apple = 'apple';

    /** Libellé affiché à l'utilisateur. */
    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Apple => 'Apple',
        };
    }
}
