<?php

namespace App\Catalog;

use App\Entity\Product;
use App\Entity\SupplierProfile;
use App\Enum\Currency;
use App\Repository\SupplierProfileRepository;
use Psr\Clock\ClockInterface;

/**
 * Transforme les produits en tableaux simples pour Twig et le JSON : les pages ne reçoivent jamais d'entités Doctrine,
 * donc rien qui n'ait été décidé ici (pas de relation cachée, pas de données du compte du fournisseur au-delà du nécessaire).
 */
final class ProductPresenter
{
    /** Nom affiché pour un produit vendu par GoBuy lui-même. */
    public const GOBUY_NAME = 'GoBuy';

    public function __construct(
        private readonly SupplierProfileRepository $profiles,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Produits pour la boutique (fiches, grilles). Même forme que celle attendue par templates/product/_card.html.twig.
     *
     * @param list<Product> $products
     *
     * @return list<array<string, mixed>>
     */
    public function storefront(array $products): array
    {
        $names = $this->companyNames($products);

        return array_map(fn (Product $p): array => $this->storefrontOne($p, $names), $products);
    }

    /** @param array<string, string> $names owner id => nom de société (voir companyNames) */
    public function storefrontOne(Product $p, array $names): array
    {
        return [
            'id' => $p->getId()->toRfc4122(),
            'name' => $p->getName(),
            'description' => (string) $p->getDescription(),
            'category' => $p->getCategory(),
            'price' => $p->getPriceMga(),
            'priceOverrides' => $p->getPriceOverrides(),
            'originalPrice' => null,
            'images' => [],            // pas encore de photos de produit : les gabarits affichent un pictogramme
            'supplierName' => $this->ownerName($p, $names),
            'brand' => '',
            'rating' => 0,
            'reviewsCount' => 0,
            'featured' => $p->isFeatured($this->clock->now()),
        ];
    }

    /**
     * Ligne de l'espace fournisseur (« Mes produits »).
     *
     * @return array<string, mixed>
     */
    public function supplierRow(Product $p): array
    {
        $prices = [];
        foreach ($p->getPriceOverrides() as $code => $minor) {
            $prices[$code] = Currency::from($code)->toMajor($minor);
        }

        return [
            'id' => $p->getId()->toRfc4122(),
            'name' => $p->getName(),
            'description' => (string) $p->getDescription(),
            'category' => (string) $p->getCategory(),
            'reference' => (string) $p->getReference(),
            'priceMga' => $p->getPriceMga(),
            'prices' => $prices,
            'status' => $p->getStatus()->value,
            'removedReason' => $p->getRemovedReason(),
            'createdAt' => $p->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * Ligne de l'administration (« Produits »).
     *
     * @param array<string, string> $names owner id => nom de société
     *
     * @return array<string, mixed>
     */
    public function adminRow(Product $p, array $names): array
    {
        $owner = $p->getOwner();

        return $this->supplierRow($p) + [
            'ownerId' => $owner?->getId()->toRfc4122(),
            'ownerName' => $this->ownerName($p, $names),
            'ownerEmail' => $owner?->getEmail(),
            'gobuy' => $p->isGobuyOwned(),
            'flagged' => $p->isFlagged(),
            'flagReason' => $p->getFlagReason(),
            'featured' => $p->isFeatured($this->clock->now()),
            'featuredUntil' => $p->getFeaturedUntil()?->format(\DATE_ATOM),
            'removedAt' => $p->getRemovedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * Noms de société des fournisseurs propriétaires de ces produits, en UNE requête.
     *
     * @param list<Product> $products
     *
     * @return array<string, string> identifiant du fournisseur => nom de la société
     */
    public function companyNames(array $products): array
    {
        $owners = [];
        foreach ($products as $p) {
            if (null !== $p->getOwner()) {
                $owners[$p->getOwner()->getId()->toRfc4122()] = $p->getOwner();
            }
        }
        if ([] === $owners) {
            return [];
        }

        $names = [];
        foreach ($this->profiles->findBy(['user' => array_values($owners)]) as $profile) {
            \assert($profile instanceof SupplierProfile);
            $names[$profile->getUser()->getId()->toRfc4122()] = $profile->getCompanyName();
        }

        return $names;
    }

    /** @param array<string, string> $names */
    private function ownerName(Product $p, array $names): string
    {
        $owner = $p->getOwner();

        return null === $owner ? self::GOBUY_NAME : ($names[$owner->getId()->toRfc4122()] ?? $owner->getFullName());
    }
}
