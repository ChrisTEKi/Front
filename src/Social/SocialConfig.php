<?php

namespace App\Social;

use App\Enum\SocialProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Identifiants Google / Apple lus dans les variables d'environnement (.env.local en production, jamais dans git).
 *
 * Google : créer un « ID client OAuth » (type Application Web) sur https://console.cloud.google.com/apis/credentials
 *          et y déclarer l'URI de redirection <DEFAULT_URI>/auth/social/google/callback.
 * Apple  : créer un « Services ID » + une clé « Sign in with Apple » sur https://developer.apple.com/account
 *          et y déclarer l'URI <DEFAULT_URI>/auth/social/apple/callback (HTTPS obligatoire chez Apple).
 * Tant qu'un fournisseur n'est pas configuré, son bouton affiche un message clair au lieu d'échouer.
 */
final class SocialConfig
{
    /** Chemin ABSOLU de la clé privée Apple (.p8). */
    public readonly string $applePrivateKeyPath;

    public function __construct(
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')]
        public readonly string $googleClientId,
        #[Autowire('%env(GOOGLE_CLIENT_SECRET)%')]
        public readonly string $googleClientSecret,
        #[Autowire('%env(APPLE_CLIENT_ID)%')]
        public readonly string $appleClientId,
        #[Autowire('%env(APPLE_TEAM_ID)%')]
        public readonly string $appleTeamId,
        #[Autowire('%env(APPLE_KEY_ID)%')]
        public readonly string $appleKeyId,
        #[Autowire('%env(APPLE_PRIVATE_KEY_PATH)%')]
        string $applePrivateKeyPath,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        // Un chemin relatif (ex. « config/secrets/apple.p8 ») est interprété depuis la racine du projet.
        $this->applePrivateKeyPath = '' === $applePrivateKeyPath || str_starts_with($applePrivateKeyPath, '/')
            ? $applePrivateKeyPath
            : $projectDir.'/'.$applePrivateKeyPath;
    }

    public function isConfigured(SocialProvider $provider): bool
    {
        return match ($provider) {
            SocialProvider::Google => '' !== $this->googleClientId && '' !== $this->googleClientSecret,
            SocialProvider::Apple => '' !== $this->appleClientId && '' !== $this->appleTeamId && '' !== $this->appleKeyId && '' !== $this->applePrivateKeyPath && is_readable($this->applePrivateKeyPath),
        };
    }

    public function clientId(SocialProvider $provider): string
    {
        return SocialProvider::Google === $provider ? $this->googleClientId : $this->appleClientId;
    }
}
