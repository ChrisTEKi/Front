<?php

namespace App\Account;

/**
 * Une action sur SON PROPRE compte est refusée pour une raison prévue (mauvais mot de passe, code faux, règle métier…).
 * L'API la traduit en message clair (domaine « auth », préfixe « error. »).
 */
final class AccountActionException extends \RuntimeException
{
    /**
     * @param string               $reason clé de traduction (ex. « step_up_failed », « open_orders »)
     * @param array<string, mixed> $params valeurs à insérer dans le message
     */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct('Action refusée : '.$reason);
    }
}
