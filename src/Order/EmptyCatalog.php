<?php

namespace App\Order;

/**
 * Catalogue vide, pour un déploiement sans produits. Le catalogue réel est App\Catalog\DoctrineCatalog (table `products`).
 */
final class EmptyCatalog implements CatalogInterface
{
    public function find(string $ref): ?CatalogItem
    {
        return null;
    }
}
