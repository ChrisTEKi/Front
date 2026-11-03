<?php

namespace App\Moderation;

/**
 * Une action de sanction ou de contestation est refusée pour une raison prévue (règle métier, motif manquant, pièce invalide…).
 * L'API la traduit en message clair (domaine « sanctions », préfixe « error. »).
 */
final class ModerationException extends \RuntimeException
{
    /**
     * @param string               $reason clé de traduction (ex. « reason_required », « appeal_open »)
     * @param array<string, mixed> $params valeurs à insérer dans le message
     */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct('Action refusée : '.$reason);
    }
}
