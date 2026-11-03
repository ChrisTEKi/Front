<?php

namespace App\Entity;

use App\Enum\Currency;
use App\Enum\ProductStatus;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Produit du catalogue — table `products`.
 *
 * PROPRIÉTAIRE : un fournisseur (`owner`) ou GoBuy lui-même (`owner` = NULL). Quand un compte fournisseur est supprimé
 * définitivement, son catalogue disparaît avec lui (suppression en cascade) ; les commandes passées gardent leur propre
 * copie du nom et du prix (voir OrderItem).
 *
 * PRIX : `priceMga` est le prix de base en Ariary (entier). `priceOverrides` contient les prix SPÉCIFIQUES par devise
 * saisis par le vendeur (ex. {"EUR": 1250, "USD": 1350} en centimes) ; une devise absente est convertie au taux du jour.
 *
 * MODÉRATION (réservée aux administrateurs) : retrait (statut « removed » + motif), signalement (marque « à examiner »),
 * mise en avant (éventuellement jusqu'à une date). Un produit retiré par un administrateur ne peut être réactivé que par
 * un administrateur.
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products', options: ['comment' => 'Catalogue : produits des fournisseurs et de GoBuy (owner NULL).'])]
#[ORM\Index(name: 'idx_products_owner', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_products_status', columns: ['status'])]
#[ORM\Index(name: 'idx_products_featured', columns: ['featured_at'])]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Fournisseur propriétaire ; NULL = produit vendu par GoBuy. Supprimé avec le compte. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $owner;

    /** Référence interne du vendeur (SKU), facultative. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Rayon (clé libre, ex. « cat-mode »). */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $category = null;

    /** Prix de base en Ariary (entier, sans centimes). */
    #[ORM\Column(name: 'price_mga', type: Types::INTEGER, options: ['comment' => 'Prix unitaire de base en Ariary'])]
    private int $priceMga;

    /**
     * Prix spécifiques par devise, en unités mineures (centimes) : {"EUR": 1250, "USD": 1350}. L'Ariary n'y figure jamais
     * (c'est le prix de base).
     *
     * @var array<string, int>
     */
    #[ORM\Column(name: 'price_overrides', type: Types::JSON, options: ['jsonb' => true, 'comment' => 'Prix fixés par le vendeur pour une devise (unités mineures)'])]
    private array $priceOverrides = [];

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ProductStatus::class)]
    private ProductStatus $status = ProductStatus::Active;

    // ---- Retrait par un administrateur ----
    #[ORM\Column(name: 'removed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    #[ORM\Column(name: 'removed_reason', type: Types::STRING, length: 500, nullable: true)]
    private ?string $removedReason = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'removed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $removedBy = null;

    // ---- Signalement (« à examiner ») ----
    #[ORM\Column(name: 'flagged_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $flaggedAt = null;

    #[ORM\Column(name: 'flag_reason', type: Types::STRING, length: 500, nullable: true)]
    private ?string $flagReason = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'flagged_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $flaggedBy = null;

    // ---- Mise en avant ----
    #[ORM\Column(name: 'featured_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $featuredAt = null;

    /** Fin de la mise en avant ; NULL = jusqu'à décision contraire. */
    #[ORM\Column(name: 'featured_until', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $featuredUntil = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'featured_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $featuredBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, int> $priceOverrides prix par devise (clés : « EUR », « USD »), unités mineures
     */
    public function __construct(?User $owner, string $name, int $priceMga, \DateTimeImmutable $now, ?string $description = null, ?string $category = null, ?string $reference = null, array $priceOverrides = [])
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->name = $name;
        $this->priceMga = $priceMga;
        $this->description = $description;
        $this->category = $category;
        $this->reference = $reference;
        $this->priceOverrides = self::cleanOverrides($priceOverrides);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Modifie les informations commerciales (nom, description, rayon, référence, prix). Ne touche ni au statut ni à la modération.
     *
     * @param array<string, int> $priceOverrides
     */
    public function update(string $name, int $priceMga, ?string $description, ?string $category, ?string $reference, array $priceOverrides, \DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->priceMga = $priceMga;
        $this->description = $description;
        $this->category = $category;
        $this->reference = $reference;
        $this->priceOverrides = self::cleanOverrides($priceOverrides);
        $this->updatedAt = $now;
    }

    /** Ne garde que des devises connues, hors devise de base, avec des montants entiers strictement positifs. */
    private static function cleanOverrides(array $overrides): array
    {
        $clean = [];
        foreach ($overrides as $code => $minor) {
            $currency = Currency::tryFrom((string) $code);
            if (null === $currency || Currency::BASE === $currency || !\is_int($minor) || $minor <= 0) {
                continue;
            }
            $clean[$currency->value] = $minor;
        }
        ksort($clean);

        return $clean;
    }

    // ------------------------------------------------------------------ Statut

    /** Le propriétaire masque ou réaffiche son produit. Impossible si un administrateur l'a retiré. */
    public function setVisible(bool $visible, \DateTimeImmutable $now): void
    {
        if (ProductStatus::Removed === $this->status) {
            throw new \LogicException('Un produit retiré par un administrateur ne peut pas être modifié par son propriétaire.');
        }
        $this->status = $visible ? ProductStatus::Active : ProductStatus::Hidden;
        $this->updatedAt = $now;
    }

    /** Retrait par un administrateur. */
    public function remove(User $admin, string $reason, \DateTimeImmutable $now): void
    {
        $this->status = ProductStatus::Removed;
        $this->removedAt = $now;
        $this->removedReason = $reason;
        $this->removedBy = $admin;
        $this->updatedAt = $now;
    }

    /** Rétablissement par un administrateur : le produit redevient actif. */
    public function restore(\DateTimeImmutable $now): void
    {
        $this->status = ProductStatus::Active;
        $this->removedAt = null;
        $this->removedReason = null;
        $this->removedBy = null;
        $this->updatedAt = $now;
    }

    // ------------------------------------------------------------------ Modération

    public function flag(User $admin, string $reason, \DateTimeImmutable $now): void
    {
        $this->flaggedAt = $now;
        $this->flagReason = $reason;
        $this->flaggedBy = $admin;
        $this->updatedAt = $now;
    }

    public function unflag(\DateTimeImmutable $now): void
    {
        $this->flaggedAt = null;
        $this->flagReason = null;
        $this->flaggedBy = null;
        $this->updatedAt = $now;
    }

    public function feature(User $admin, ?\DateTimeImmutable $until, \DateTimeImmutable $now): void
    {
        $this->featuredAt = $now;
        $this->featuredUntil = $until;
        $this->featuredBy = $admin;
        $this->updatedAt = $now;
    }

    public function unfeature(\DateTimeImmutable $now): void
    {
        $this->featuredAt = null;
        $this->featuredUntil = null;
        $this->featuredBy = null;
        $this->updatedAt = $now;
    }

    // ------------------------------------------------------------------ Lecture

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    /** Vendu par GoBuy lui-même (pas de fournisseur). */
    public function isGobuyOwned(): bool
    {
        return null === $this->owner;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getPriceMga(): int
    {
        return $this->priceMga;
    }

    /** @return array<string, int> */
    public function getPriceOverrides(): array
    {
        return $this->priceOverrides;
    }

    /** Prix spécifique saisi par le vendeur pour cette devise (unités mineures), ou null s'il n'y en a pas. */
    public function getOverrideFor(Currency $currency): ?int
    {
        return $this->priceOverrides[$currency->value] ?? null;
    }

    public function getStatus(): ProductStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return ProductStatus::Active === $this->status;
    }

    public function isRemoved(): bool
    {
        return ProductStatus::Removed === $this->status;
    }

    public function getRemovedAt(): ?\DateTimeImmutable
    {
        return $this->removedAt;
    }

    public function getRemovedReason(): ?string
    {
        return $this->removedReason;
    }

    public function isFlagged(): bool
    {
        return null !== $this->flaggedAt;
    }

    public function getFlaggedAt(): ?\DateTimeImmutable
    {
        return $this->flaggedAt;
    }

    public function getFlagReason(): ?string
    {
        return $this->flagReason;
    }

    /** Mis en avant à cet instant (la date de fin éventuelle n'est pas dépassée). */
    public function isFeatured(\DateTimeImmutable $now): bool
    {
        return null !== $this->featuredAt && (null === $this->featuredUntil || $this->featuredUntil > $now);
    }

    public function getFeaturedAt(): ?\DateTimeImmutable
    {
        return $this->featuredAt;
    }

    public function getFeaturedUntil(): ?\DateTimeImmutable
    {
        return $this->featuredUntil;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
