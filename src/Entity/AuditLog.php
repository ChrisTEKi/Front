<?php

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Journal d'audit — table `audit_logs` : trace INALTÉRABLE de qui a fait quoi, quand et d'où.
 *
 * Alimenté par AuditLogger pour : connexions admin (réussies/échouées), création/modification d'admins,
 * approbation de fournisseurs, exports de données, demandes de suppression de compte, etc.
 *
 * On ne met PAS de clé étrangère vers `users` : le journal doit survivre à la suppression d'un compte
 * (on garde donc l'identifiant et l'e-mail de l'acteur « figés » au moment de l'action).
 * L'application n'expose aucune fonction de modification ou de suppression de ces lignes.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs', options: ['comment' => 'Journal d\'audit : qui a fait quoi, quand, d\'où. Pas de clé étrangère : survit à la suppression des comptes.'])]
#[ORM\Index(name: 'idx_audit_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_id'])]
#[ORM\Index(name: 'idx_audit_action', columns: ['action'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Qui a agi (identifiant de l'utilisateur), NULL pour une action anonyme ou système. */
    #[ORM\Column(name: 'actor_id', type: UuidType::NAME, nullable: true, options: ['comment' => 'Identifiant de l\'auteur (sans clé étrangère : survit à la suppression du compte)'])]
    private ?Uuid $actorId;

    /** E-mail de l'acteur au moment des faits (copie figée). */
    #[ORM\Column(name: 'actor_email', type: Types::STRING, length: 180, nullable: true)]
    private ?string $actorEmail;

    /** Code de l'action, ex. « admin.created », « export.clients », « supplier.approved ». */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $action;

    /** Type de l'objet visé (« user », « supplier », « admin_role »…). */
    #[ORM\Column(name: 'target_type', type: Types::STRING, length: 32, nullable: true)]
    private ?string $targetType;

    /** Identifiant de l'objet visé. */
    #[ORM\Column(name: 'target_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $targetId;

    /** Détails complémentaires (JSONB) — jamais de secret ni de mot de passe. */
    #[ORM\Column(type: Types::JSON, options: ['comment' => 'Détails JSON (jamais de secret)', 'jsonb' => true])]
    private array $metadata;

    /** Adresse IP de l'auteur de la requête. */
    #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress;

    /** Navigateur (tronqué à 255 caractères). */
    #[ORM\Column(name: 'user_agent', type: Types::STRING, length: 255, nullable: true)]
    private ?string $userAgent;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        \DateTimeImmutable $now,
        ?Uuid $actorId,
        ?string $actorEmail,
        string $action,
        ?string $targetType,
        ?string $targetId,
        array $metadata,
        ?string $ipAddress,
        ?string $userAgent,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $now;
        $this->actorId = $actorId;
        $this->actorEmail = $actorEmail;
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->metadata = $metadata;
        $this->ipAddress = $ipAddress;
        $this->userAgent = null !== $userAgent ? mb_substr($userAgent, 0, 255) : null;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActorEmail(): ?string
    {
        return $this->actorEmail;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function getTargetId(): ?string
    {
        return $this->targetId;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }
}
