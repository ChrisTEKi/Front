<?php

namespace App\Admin;

/**
 * Une action d'administration est refusée pour une raison prévue (droit manquant, règle métier, mot de passe de
 * confirmation faux…). L'API la traduit en message clair (domaine « admin », préfixe « error. »).
 */
final class AdminActionException extends \RuntimeException
{
    /**
     * @param string               $reason clé de traduction (ex. « step_up_failed », « email_taken »)
     * @param array<string, mixed> $params valeurs à insérer dans le message
     */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct('Action refusée : '.$reason);
    }
}
