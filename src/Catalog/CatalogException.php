<?php

namespace App\Catalog;

/**
 * Une action sur le catalogue est refusée pour une raison prévue (produit introuvable, compte non autorisé, motif manquant…).
 * L'API la traduit en message clair (domaine « catalog », préfixe « error. »).
 */
final class CatalogException extends \RuntimeException
{
    /**
     * @param string               $reason clé de traduction (ex. « not_found », « reason_required »)
     * @param array<string, mixed> $params valeurs à insérer dans le message
     */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct('Action refusée : '.$reason);
    }
}
