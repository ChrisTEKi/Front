<?php

namespace App\Entity;

use App\Enum\AppealStatus;
use App\Repository\SupplierAppealRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Contestation d'un fournisseur contre le refus (ou le retrait d'approbation) de son dossier — table `supplier_appeals`.
 *
 * Le fournisseur écrit un message et peut joindre des pièces (voir SupplierAppealDocument). L'équipe l'examine : elle l'ACCEPTE
 * (le dossier est re-validé) ou la REJETTE (la décision est maintenue, avec une explication). On garde une copie du statut et
 * du motif contestés : même si le dossier change ensuite, on sait à quelle décision la contestation répondait.
 *
 * La contestation disparaît avec le compte du fournisseur (suppression en cascade).
 */
#[ORM\Entity(repositoryClass: SupplierAppealRepository::class)]
#[ORM\Table(name: 'supplier_appeals', options: ['comment' => 'Contestations de décisions sur un dossier fournisseur (message + pièces jointes).'])]
#[ORM\Index(name: 'idx_appeals_supplier', columns: ['supplier_id', 'created_at'])]
#[ORM\Index(name: 'idx_appeals_status', columns: ['status'])]
class SupplierAppeal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: false, onDelete: 'CASCADE')]
    private User $supplier;

    /** Message du fournisseur (arguments, explications). */
    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(type: Types::STRING, length: 12, enumType: AppealStatus::class)]
    private AppealStatus $status = AppealStatus::Open;

    /** Statut du dossier au moment du dépôt (« rejected » ou « suspended »). */
    #[ORM\Column(name: 'contested_status', type: Types::STRING, length: 16)]
    private string $contestedStatus;

    /** Motif communiqué au fournisseur à ce moment-là. */
    #[ORM\Column(name: 'contested_reason', type: Types::TEXT, nullable: true)]
    private ?string $contestedReason;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    /** Explication de l'équipe, montrée au fournisseur. */
    #[ORM\Column(name: 'decision_note', type: Types::STRING, length: 500, nullable: true)]
    private ?string $decisionNote = null;

    /** @var Collection<int, SupplierAppealDocument> */
    #[ORM\OneToMany(targetEntity: SupplierAppealDocument::class, mappedBy: 'appeal', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $documents;

    public function __construct(User $supplier, string $message, string $contestedStatus, ?string $contestedReason, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->supplier = $supplier;
        $this->message = $message;
        $this->contestedStatus = $contestedStatus;
        $this->contestedReason = $contestedReason;
        $this->createdAt = $now;
        $this->documents = new ArrayCollection();
    }

    public function addDocument(SupplierAppealDocument $document): void
    {
        $this->documents->add($document);
    }

    /** Acceptée : le dossier a été re-validé. */
    public function accept(User $admin, ?string $note, \DateTimeImmutable $now): void
    {
        $this->close(AppealStatus::Accepted, $admin, $note, $now);
    }

    /** Rejetée : la décision est maintenue. */
    public function dismiss(User $admin, string $note, \DateTimeImmutable $now): void
    {
        $this->close(AppealStatus::Dismissed, $admin, $note, $now);
    }

    private function close(AppealStatus $status, User $admin, ?string $note, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->decidedBy = $admin;
        $this->decidedAt = $now;
        $this->decisionNote = null !== $note && '' !== trim($note) ? mb_substr(trim($note), 0, 500) : null;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSupplier(): User
    {
        return $this->supplier;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatus(): AppealStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return AppealStatus::Open === $this->status;
    }

    public function getContestedStatus(): string
    {
        return $this->contestedStatus;
    }

    public function getContestedReason(): ?string
    {
        return $this->contestedReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    /** @return Collection<int, SupplierAppealDocument> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }
}
