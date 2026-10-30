<?php

namespace App\Order;

/**
 * POINT DE BRANCHEMENT DU CATALOGUE.
 *
 * Le catalogue produits n'est pas encore en base de données : le front-end affiche « Aucun produit » (aucune donnée
 * fictive). Or une commande ne doit JAMAIS faire confiance au prix envoyé par le navigateur. OrderService demande donc
 * nom, prix et fournisseur à cette interface, côté serveur.
 *
 * POUR BRANCHER LE CATALOGUE : créez une classe qui implémente cette interface (lecture de votre table `products`, par
 * exemple) et déclarez-la comme alias dans config/services.yaml :
 *
 *     App\Order\CatalogInterface: '@App\Catalog\DoctrineCatalog'
 *
 * Tant qu'aucun catalogue n'est branché, EmptyCatalog répond « produit inconnu » : aucune commande ne peut aboutir
 * (c'est voulu : mieux vaut refuser que vendre à un prix inventé).
 */
interface CatalogInterface
{
    /** Retrouve un produit par son identifiant de catalogue, ou null s'il n'existe pas. */
    public function find(string $ref): ?CatalogItem;
}
