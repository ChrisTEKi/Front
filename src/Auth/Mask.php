<?php

namespace App\Auth;

/**
 * Masquage d'informations personnelles pour les messages affichés à l'écran
 * (« code envoyé à j•••@gmail.com ») : on confirme la destination sans la divulguer en entier.
 */
final class Mask
{
    private function __construct()
    {
    }

    /** « jean.dupont@gmail.com » → « j•••@gmail.com » */
    public static function email(string $email): string
    {
        $at = strrpos($email, '@');
        if (false === $at || $at < 1) {
            return '•••';
        }

        return mb_substr($email, 0, 1).'•••'.substr($email, $at);
    }
}
