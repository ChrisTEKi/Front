<?php

namespace App\Entity;

use App\Enum\ProductCondition;
use App\Enum\ProductStatus;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(columns: ['status', 'category_slug'])]
class Product
{
    public const MAX_IMAGES = 6;
    public const PLACEHOLDER_IMAGE = '/images/placeholder-product.svg';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Supplier $supplier;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Indiquez le nom du produit.')]
    #[Assert\Length(max: 160)]
    private string $name = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Indiquez la marque.')]
    #[Assert\Length(max: 80)]
    private string $brand = '';

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $model = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $sku = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Ajoutez une description.')]
    #[Assert\Length(min: 20, minMessage: 'La description doit contenir au moins {{ limit }} caractères.', max: 5000)]
    private string $description = '';

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Choisissez un rayon.')]
    private string $categorySlug = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $subcategory = null;

    #[ORM\Column(name: 'item_condition', enumType: ProductCondition::class)]
    private ProductCondition $condition = ProductCondition::New;

    /** Selling price in Ariary (no decimals). */
    #[ORM\Column]
    #[Assert\NotBlank(message: 'Indiquez le prix.')]
    #[Assert\Positive(message: 'Le prix doit être supérieur à 0.')]
    private ?int $price = null;

    /** Strike-through price, shown with a discount badge when higher than the price. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le prix barré doit être supérieur à 0.')]
    private ?int $originalPrice = null;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Indiquez le stock.')]
    #[Assert\PositiveOrZero(message: 'Le stock ne peut pas être négatif.')]
    private ?int $stock = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 120, notInRangeMessage: 'La garantie doit être comprise entre {{ min }} et {{ max }} mois.')]
    private ?int $warrantyMonths = null;

    #[ORM\Column(enumType: ProductStatus::class)]
    private ProductStatus $status = ProductStatus::Draft;

    /** @var list<string> public paths, e.g. /uploads/products/abc.jpg */
    #[ORM\Column(type: Types::JSON)]
    private array $imagePaths = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(Supplier $supplier): static
    {
        $this->supplier = $supplier;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getBrand(): string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): static
    {
        $this->brand = trim((string) $brand);

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): static
    {
        $this->sku = $sku;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = trim((string) $description);

        return $this;
    }

    public function getCategorySlug(): string
    {
        return $this->categorySlug;
    }

    public function setCategorySlug(?string $categorySlug): static
    {
        $this->categorySlug = (string) $categorySlug;

        return $this;
    }

    public function getSubcategory(): ?string
    {
        return $this->subcategory;
    }

    public function setSubcategory(?string $subcategory): static
    {
        $this->subcategory = $subcategory;

        return $this;
    }

    public function getCondition(): ProductCondition
    {
        return $this->condition;
    }

    public function setCondition(ProductCondition $condition): static
    {
        $this->condition = $condition;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getOriginalPrice(): ?int
    {
        return $this->originalPrice;
    }

    public function setOriginalPrice(?int $originalPrice): static
    {
        $this->originalPrice = $originalPrice;

        return $this;
    }

    public function getDiscountPercent(): int
    {
        if (null === $this->originalPrice || null === $this->price || $this->originalPrice <= $this->price) {
            return 0;
        }

        return (int) round(($this->originalPrice - $this->price) / $this->originalPrice * 100);
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(?int $stock): static
    {
        $this->stock = $stock;

        return $this;
    }

    public function adjustStock(int $delta): static
    {
        $this->stock = max(0, ($this->stock ?? 0) + $delta);

        return $this;
    }

    public function getWarrantyMonths(): ?int
    {
        return $this->warrantyMonths;
    }

    public function setWarrantyMonths(?int $warrantyMonths): static
    {
        $this->warrantyMonths = $warrantyMonths;

        return $this;
    }

    public function getStatus(): ProductStatus
    {
        return $this->status;
    }

    public function setStatus(ProductStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isOnline(): bool
    {
        return ProductStatus::Active === $this->status;
    }

    /** @return list<string> */
    public function getImagePaths(): array
    {
        return $this->imagePaths;
    }

    /** @param list<string> $imagePaths */
    public function setImagePaths(array $imagePaths): static
    {
        $this->imagePaths = array_values($imagePaths);

        return $this;
    }

    /**
     * Images for the storefront templates: never empty, so `product.images[0]` is always safe.
     *
     * @return list<string>
     */
    public function getImages(): array
    {
        return [] === $this->imagePaths ? [self::PLACEHOLDER_IMAGE] : $this->imagePaths;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // ---- Storefront template helpers (product/_card.html.twig, product/show.html.twig) ----

    public function getSupplierName(): string
    {
        return $this->supplier->getShopName();
    }

    /** No review system yet: templates hide the rating while the count is 0. */
    public function getRating(): float
    {
        return 0.0;
    }

    public function getReviewsCount(): int
    {
        return 0;
    }
}
