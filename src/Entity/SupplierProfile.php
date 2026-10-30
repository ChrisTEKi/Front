<?php

namespace App\Entity;

use App\Enum\SupplierStatus;
use App\Repository\SupplierProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dossier d'un fournisseur / vendeur — table `supplier_profiles` (1 ligne par compte fournisseur).
 *
 * La clé primaire EST l'identifiant de l'utilisateur (relation 1-1) : pas de colonne `id` séparée.
 * Le fournisseur reste en statut PENDING tant qu'un admin ne l'a pas approuvé.
 */
#[ORM\Entity(repositoryClass: SupplierProfileRepository::class)]
#[ORM\Table(name: 'supplier_profiles', options: ['comment' => 'Dossier d\'un fournisseur (société, adresse) + statut d\'approbation par un admin. 1 ligne par compte fournisseur.'])]
#[ORM\Index(name: 'idx_supplier_status', columns: ['status'])]
class SupplierProfile
{
    /** Le compte utilisateur propriétaire (clé primaire ET clé étrangère). Supprimé avec lui. */
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'company_name', type: Types::STRING, length: 150)]
    private string $companyName;

    /** Adresse de l'entrepôt / magasin. */
    #[ORM\Column(name: 'company_address', type: Types::STRING, length: 255)]
    private string $companyAddress;

    /** Présentation des produits, marques, garanties (champ libre facultatif). */
    #[ORM\Column(name: 'company_description', type: Types::TEXT, nullable: true)]
    private ?string $companyDescription;

    /**
     * Réponses détaillées de l'assistant « Devenir partenaire » (secteur, produits, logistique, programme solidaire…).
     * JSONB, NULL pour les dossiers sans détail. Toujours nettoyé par App\Auth\SupplierApplication::sanitize() AVANT d'arriver ici.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Réponses de l\'assistant Devenir partenaire (codes whitelistés, nettoyées côté serveur). NULL = dossier sans détail', 'jsonb' => true])]
    private ?array $application = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: SupplierStatus::class, options: ['comment' => 'pending | approved | rejected | suspended'])]
    private SupplierStatus $status = SupplierStatus::Pending;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    /** Date de la décision de l'admin (approbation ou refus). */
    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    /** Admin qui a pris la décision (NULL si son compte a été supprimé depuis). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    /** Motif communiqué au fournisseur en cas de refus. */
    #[ORM\Column(name: 'rejection_reason', type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    /**
     * @param array<string, mixed>|null $application réponses DÉJÀ nettoyées (SupplierApplication::sanitize)
     */
    public function __construct(User $user, string $companyName, string $companyAddress, ?string $companyDescription, \DateTimeImmutable $now, ?array $application = null)
    {
        $this->user = $user;
        $this->companyName = trim($companyName);
        $this->companyAddress = trim($companyAddress);
        $this->companyDescription = null !== $companyDescription && '' !== trim($companyDescription) ? trim($companyDescription) : null;
        $this->submittedAt = $now;
        $this->application = [] === $application ? null : $application;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function getCompanyAddress(): string
    {
        return $this->companyAddress;
    }

    public function getCompanyDescription(): ?string
    {
        return $this->companyDescription;
    }

    /** @return array<string, mixed>|null */
    public function getApplication(): ?array
    {
        return $this->application;
    }

    public function getStatus(): SupplierStatus
    {
        return $this->status;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function isApproved(): bool
    {
        return SupplierStatus::Approved === $this->status;
    }

    public function isPending(): bool
    {
        return SupplierStatus::Pending === $this->status;
    }

    /** Un admin approuve le dossier. */
    public function approve(User $admin, \DateTimeImmutable $now): void
    {
        $this->status = SupplierStatus::Approved;
        $this->reviewedBy = $admin;
        $this->reviewedAt = $now;
        $this->rejectionReason = null;
    }

    /** Un admin refuse le dossier avec un motif. */
    public function reject(User $admin, string $reason, \DateTimeImmutable $now): void
    {
        $this->status = SupplierStatus::Rejected;
        $this->reviewedBy = $admin;
        $this->reviewedAt = $now;
        $this->rejectionReason = trim($reason);
    }

    /** Un admin retire l'approbation d'un fournisseur déjà approuvé. */
    public function suspend(User $admin, string $reason, \DateTimeImmutable $now): void
    {
        $this->status = SupplierStatus::Suspended;
        $this->reviewedBy = $admin;
        $this->reviewedAt = $now;
        $this->rejectionReason = trim($reason);
    }
}
