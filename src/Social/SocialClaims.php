<?php

namespace App\Social;

use App\Enum\SocialProvider;

/**
 * Identité garantie par Google/Apple après validation du jeton d'identité (ID token).
 */
final class SocialClaims
{
    public function __construct(
        public readonly SocialProvider $provider,
        /** Identifiant stable et unique de la personne chez le fournisseur (« sub »). L'e-mail peut changer, pas lui. */
        public readonly string $subject,
        public readonly ?string $email,
        /** Le fournisseur certifie-t-il que la personne contrôle cette adresse e-mail ? */
        public readonly bool $emailVerified,
        public readonly ?string $name,
    ) {
    }
}
