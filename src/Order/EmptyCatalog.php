<?php

namespace App\Order;

/**
 * Catalogue par défaut : vide. À remplacer par une vraie implémentation de CatalogInterface (voir cette interface).
 */
final class EmptyCatalog implements CatalogInterface
{
    public function find(string $ref): ?CatalogItem
    {
        return null;
    }
}
