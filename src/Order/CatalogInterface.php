<?php

namespace App\Order;

/**
 * POINT DE BRANCHEMENT DU CATALOGUE.
 *
 * Une commande ne doit JAMAIS faire confiance au prix envoyé par le navigateur. OrderService demande donc nom, prix et
 * fournisseur à cette interface, côté serveur.
 *
 * Implémentation branchée : App\Catalog\DoctrineCatalog (table `products`, voir config/services.yaml). Un produit n'y est
 * « disponible » que s'il est actif ET vendu par GoBuy ou par un fournisseur approuvé dont le compte est actif.
 * EmptyCatalog (toujours vide) reste disponible pour un déploiement sans catalogue : aucune commande ne peut alors aboutir
 * (c'est voulu : mieux vaut refuser que vendre à un prix inventé).
 */
interface CatalogInterface
{
    /** Retrouve un produit par son identifiant de catalogue, ou null s'il n'existe pas. */
    public function find(string $ref): ?CatalogItem;
}
