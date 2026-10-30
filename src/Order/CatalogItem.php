<?php

namespace App\Order;

use Symfony\Component\Uid\Uuid;

/**
 * Ce que la boutique sait d'un produit AU MOMENT de la commande : nom, prix, fournisseur, disponibilité.
 *
 * Ces valeurs viennent TOUJOURS du serveur (jamais du navigateur) : le client ne peut pas choisir son prix.
 */
final class CatalogItem
{
    public function __construct(
        /** Identifiant du produit dans le catalogue (celui que le panier envoie). */
        public readonly string $ref,
        public readonly string $name,
        /** Prix unitaire en Ariary (entier). */
        public readonly int $unitPrice,
        /** Fournisseur vendeur (null = vendu par GoBuy). */
        public readonly ?Uuid $supplierId = null,
        /** false = produit retiré de la vente ou en rupture : la commande est refusée. */
        public readonly bool $available = true,
    ) {
    }
}
