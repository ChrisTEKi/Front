<?php

namespace App\Order;

/**
 * Une commande est refusée pour une raison prévue (pas une panne) : l'API la traduit en message clair pour le client.
 */
final class OrderRefusedException extends \RuntimeException
{
    /**
     * @param string               $reason clé de traduction (domaine « orders », préfixe « error. ») : phone_not_verified, empty_cart…
     * @param array<string, mixed> $params valeurs à insérer dans le message (ex. ['%ref%' => 'abc'])
     */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct('Commande refusée : '.$reason);
    }
}
