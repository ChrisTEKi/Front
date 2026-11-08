<?php

namespace App\Catalog;

use App\Order\CatalogInterface;
use App\Order\CatalogItem;
use App\Repository\ProductRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Catalogue RÉEL : relit nom, prix et fournisseur dans la table `products` au moment de la commande (le navigateur ne
 * décide jamais du prix). Un produit masqué, retiré, ou dont le fournisseur n'est plus approuvé / actif est « indisponible » :
 * la commande est refusée avec le nom du produit (voir OrderService).
 *
 * La référence du panier est l'identifiant (UUID) du produit.
 */
final class DoctrineCatalog implements CatalogInterface
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function find(string $ref): ?CatalogItem
    {
        if (!Uuid::isValid($ref)) {
            return null;
        }
        // Tous les produits sont retrouvés (même masqués) pour pouvoir dire « indisponible » plutôt que « inconnu » ;
        // la disponibilité suit la règle unique de la boutique (ProductRepository::findSellable).
        $product = $this->products->findOneByUuid($ref);
        if (null === $product) {
            return null;
        }

        return new CatalogItem(
            $product->getId()->toRfc4122(),
            $product->getName(),
            $product->getPriceMga(),
            $product->getOwner()?->getId(),
            null !== $this->products->findSellable($ref),
            $product->getPriceOverrides(),
        );
    }
}
