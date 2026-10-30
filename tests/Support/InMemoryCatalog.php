<?php

namespace App\Tests\Support;

use App\Order\CatalogInterface;
use App\Order\CatalogItem;

/**
 * Catalogue de TEST : quelques produits définis par le test lui-même, pour vérifier que les prix sont bien relus
 * côté serveur. (Aucun produit n'existe dans l'application livrée : voir App\Order\EmptyCatalog.)
 */
final class InMemoryCatalog implements CatalogInterface
{
    /** @var array<string, CatalogItem> */
    private array $items = [];

    public function add(CatalogItem $item): void
    {
        $this->items[$item->ref] = $item;
    }

    public function find(string $ref): ?CatalogItem
    {
        return $this->items[$ref] ?? null;
    }
}
