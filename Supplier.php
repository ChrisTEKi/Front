<?php

namespace App\Entity;

use App\Enum\PayoutMethod;
use App\Enum\SupplierPlan;
use App\Enum\SupplierStatus;
use App\Repository\SupplierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\Index(columns: ['status'])]
class Supplier
{
    public const ACTIVITY_INDIVIDUAL = 'particulier';
    public const ACTIVITY_COMPANY = 'entreprise';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'supplier')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120)]
    private string $shopName = '';

    #[ORM\Column(length: 140, unique: true)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $contactRole = null;

    #[ORM\Column(length: 180)]
    private string $businessEmail = '';

    #[ORM\Column(length: 30)]
    private string $phone = '';

    #[ORM\Column(length: 80)]
    private string $city = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 20)]
    private string $activityType = self::ACTIVITY_COMPANY;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $taxId = null;

    /** Category slug (see CategoryProvider). */
    #[ORM\Column(length: 60)]
    private string $mainCategory = '';

    #[ORM\Column(enumType: SupplierPlan::class)]
    private SupplierPlan $plan = SupplierPlan::Standard;

    #[ORM\Column(enumType: SupplierStatus::class)]
    private SupplierStatus $status = SupplierStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $statusReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(enumType: PayoutMethod::class, nullable: true)]
    private ?PayoutMethod $payoutMethod = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $payoutAccount = null;

    /**
     * Free-form answers collected by the application wizard (logistics,
     * catalogue size, expectations…) kept for the back-office reviewer.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $application = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Product> */
    #[ORM\OneToMany(mappedBy: 'supplier', targetEntity: Product::class)]
    private Collection $products;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->products = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        $user->setSupplier($this);

        return $this;
    }

    public function getShopName(): string
    {
        return $this->shopName;
    }

    public function setShopName(?string $shopName): static
    {
        $this->shopName = trim((string) $shopName);

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getContactRole(): ?string
    {
        return $this->contactRole;
    }

    public function setContactRole(?string $contactRole): static
    {
        $this->contactRole = $contactRole;

        return $this;
    }

    public function getBusinessEmail(): string
    {
        return $this->businessEmail;
    }

    public function setBusinessEmail(?string $businessEmail): static
    {
        $this->businessEmail = mb_strtolower(trim((string) $businessEmail));

        return $this;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = trim((string) $phone);

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = trim((string) $city);

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getActivityType(): string
    {
        return $this->activityType;
    }

    public function setActivityType(string $activityType): static
    {
        $this->activityType = $activityType;

        return $this;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function setTaxId(?string $taxId): static
    {
        $this->taxId = $taxId;

        return $this;
    }

    public function getMainCategory(): string
    {
        return $this->mainCategory;
    }

    public function setMainCategory(string $mainCategory): static
    {
        $this->mainCategory = $mainCategory;

        return $this;
    }

    public function getPlan(): SupplierPlan
    {
        return $this->plan;
    }

    public function setPlan(SupplierPlan $plan): static
    {
        $this->plan = $plan;

        return $this;
    }

    public function getStatus(): SupplierStatus
    {
        return $this->status;
    }

    public function getStatusReason(): ?string
    {
        return $this->statusReason;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    /** Records a back-office decision. Use SupplierReviewer rather than calling this directly. */
    public function applyDecision(SupplierStatus $status, ?User $reviewer, ?string $reason): static
    {
        $this->status = $status;
        $this->reviewedBy = $reviewer;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->statusReason = $reason;

        return $this;
    }

    public function getPayoutMethod(): ?PayoutMethod
    {
        return $this->payoutMethod;
    }

    public function setPayoutMethod(?PayoutMethod $payoutMethod): static
    {
        $this->payoutMethod = $payoutMethod;

        return $this;
    }

    public function getPayoutAccount(): ?string
    {
        return $this->payoutAccount;
    }

    public function setPayoutAccount(?string $payoutAccount): static
    {
        $this->payoutAccount = $payoutAccount;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getApplication(): array
    {
        return $this->application;
    }

    /** @param array<string, mixed> $application */
    public function setApplication(array $application): static
    {
        $this->application = $application;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Product> */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function isApproved(): bool
    {
        return SupplierStatus::Approved === $this->status;
    }

    public function isPending(): bool
    {
        return SupplierStatus::Pending === $this->status;
    }

    /** Only validated suppliers may publish and manage products. */
    public function canSell(): bool
    {
        return $this->isApproved();
    }
}
