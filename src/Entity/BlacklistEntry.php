<?php

namespace App\Entity;

use App\Enum\BlacklistKind;
use App\Repository\BlacklistEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Entrée de la liste noire — table `blacklist_entries`.
 *
 * Une entrée interdit une inscription (adresse e-mail, téléphone, nom, raison sociale ou adresse IP). Elle n'est volontairement
 * liée à AUCUN compte : elle survit à la suppression du compte visé, sans quoi la personne pourrait se réinscrire aussitôt.
 * La valeur est stockée NORMALISÉE (voir App\Moderation\BlacklistService::normalize). Une date d'expiration facultative
 * permet un blocage temporaire (utile pour une adresse IP, qui change de titulaire).
 */
#[ORM\Entity(repositoryClass: BlacklistEntryRepository::class)]
#[ORM\Table(name: 'blacklist_entries', options: ['comment' => 'Liste noire : e-mails, téléphones, noms, raisons sociales et adresses IP interdits d\'inscription.'])]
#[ORM\UniqueConstraint(name: 'uniq_blacklist_kind_value', columns: ['kind', 'value'])]
#[ORM\Index(name: 'idx_blacklist_created_at', columns: ['created_at'])]
class BlacklistEntry
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: BlacklistKind::class)]
    private BlacklistKind $kind;

    #[ORM\Column(type: Types::STRING, length: 255, options: ['comment' => 'Valeur normalisée'])]
    private string $value;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $reason;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** NULL = définitive. */
    #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    public function __construct(BlacklistKind $kind, string $value, string $reason, ?User $createdBy, \DateTimeImmutable $now, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->id = Uuid::v7();
        $this->kind = $kind;
        $this->value = $value;
        $this->reason = $reason;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKind(): BlacklistKind
    {
        return $this->kind;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return null === $this->expiresAt || $this->expiresAt > $now;
    }

    /** Renouvelle une entrée existante (nouveau motif, nouvelle échéance). */
    public function renew(string $reason, ?User $by, \DateTimeImmutable $now, ?\DateTimeImmutable $expiresAt): void
    {
        $this->reason = $reason;
        $this->createdBy = $by;
        $this->createdAt = $now;
        $this->expiresAt = $expiresAt;
    }
}
