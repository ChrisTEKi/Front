<?php

namespace App\Social;

/**
 * Échec de la connexion Google/Apple. Le code (« cancelled », « invalid_response », « no_email »…) devient un message
 * traduit sur la page de connexion (clé « social.error.<code> », domaine « auth »). Le détail technique va dans les logs.
 */
final class SocialAuthException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct('' !== $detail ? $detail : $reason, 0, $previous);
    }
}
