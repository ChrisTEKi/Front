<?php

namespace App\Enum;

/**
 * État d'un produit du catalogue.
 *
 * Seuls les produits « actifs » sont visibles et commandables. La différence entre « masqué » et « retiré » est la
 * personne qui a décidé : le propriétaire masque et réaffiche librement SON produit ; seul un administrateur retire
 * (ou rétablit) un produit retiré (le propriétaire ne peut alors plus le réactiver).
 */
enum ProductStatus: string
{
    /** En vente. */
    case Active = 'active';

    /** Masqué par son propriétaire (brouillon, pause, rupture…). */
    case Hidden = 'hidden';

    /** Retiré de la vente par un administrateur (motif obligatoire). */
    case Removed = 'removed';
}
