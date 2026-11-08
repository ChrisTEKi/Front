<?php

namespace App\Entity;

use App\Enum\ContractType;
use App\Enum\JobOfferStatus;
use App\Repository\JobOfferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Offre d'emploi publiée par l'équipe GoBuy — table `job_offers`.
 *
 * Le texte est écrit dans UNE langue (celle que choisit l'équipe) : il est affiché tel quel aux visiteurs, quelle que soit leur langue.
 * Une offre ouverte est visible sur /emplois jusqu'à sa date limite (facultative) ou jusqu'à sa clôture. Une offre qui a reçu des
 * candidatures ne se supprime pas : on la clôture (les candidatures restent consultables).
 */
#[ORM\Entity(repositoryClass: JobOfferRepository::class)]
#[ORM\Table(name: 'job_offers', options: ['comment' => 'Offres d\'emploi de GoBuy.'])]
#[ORM\Index(name: 'idx_job_offers_status', columns: ['status', 'published_at'])]
class JobOffer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $title;

    /** Service ou équipe (« Logistique », « Support client »…). */
    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $department;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $location;

    #[ORM\Column(name: 'contract_type', type: Types::STRING, length: 16, enumType: ContractType::class)]
    private ContractType $contractType;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** Profil recherché (compétences, expérience). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $profile;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: JobOfferStatus::class)]
    private JobOfferStatus $status = JobOfferStatus::Draft;

    /** Dernier jour pour postuler (inclus), ou null = jusqu'à la clôture. */
    #[ORM\Column(name: 'closing_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closingDate;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'published_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(?User $createdBy, string $title, ?string $department, ?string $location, ContractType $contractType, string $description, ?string $profile, ?\DateTimeImmutable $closingDate, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->createdBy = $createdBy;
        $this->title = $title;
        $this->department = $department;
        $this->location = $location;
        $this->contractType = $contractType;
        $this->description = $description;
        $this->profile = $profile;
        $this->closingDate = $closingDate;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function update(string $title, ?string $department, ?string $location, ContractType $contractType, string $description, ?string $profile, ?\DateTimeImmutable $closingDate, \DateTimeImmutable $now): void
    {
        $this->title = $title;
        $this->department = $department;
        $this->location = $location;
        $this->contractType = $contractType;
        $this->description = $description;
        $this->profile = $profile;
        $this->closingDate = $closingDate;
        $this->updatedAt = $now;
    }

    public function publish(\DateTimeImmutable $now): void
    {
        $this->status = JobOfferStatus::Open;
        $this->publishedAt ??= $now;
        $this->closedAt = null;
        $this->updatedAt = $now;
    }

    public function close(\DateTimeImmutable $now): void
    {
        $this->status = JobOfferStatus::Closed;
        $this->closedAt = $now;
        $this->updatedAt = $now;
    }

    /** Vrai si un visiteur peut la voir et postuler à cet instant : ouverte ET date limite non dépassée. */
    public function acceptsApplications(\DateTimeImmutable $now): bool
    {
        return JobOfferStatus::Open === $this->status && $this->acceptsApplicationsIfOpen($now);
    }

    /** Vrai si la date limite (comparée à l'heure de Madagascar) n'est pas dépassée, quel que soit l'état de l'offre. */
    public function acceptsApplicationsIfOpen(\DateTimeImmutable $now): bool
    {
        return null === $this->closingDate || $this->closingDate->format('Y-m-d') >= $now->setTimezone(new \DateTimeZone('Indian/Antananarivo'))->format('Y-m-d');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getContractType(): ContractType
    {
        return $this->contractType;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getProfile(): ?string
    {
        return $this->profile;
    }

    public function getStatus(): JobOfferStatus
    {
        return $this->status;
    }

    public function getClosingDate(): ?\DateTimeImmutable
    {
        return $this->closingDate;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }
}
