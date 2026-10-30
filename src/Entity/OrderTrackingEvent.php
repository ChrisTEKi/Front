<?php

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Repository\OrderTrackingEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Événement de suivi d'un colis — table `order_tracking_events`.
 * Chaque changement d'étape (confirmée, en préparation, en route, livrée, annulée) ajoute une ligne :
 * c'est l'historique affiché sur la page « Suivi de commande ».
 */
#[ORM\Entity(repositoryClass: OrderTrackingEventRepository::class)]
#[ORM\Table(name: 'order_tracking_events', options: ['comment' => 'Historique de suivi d\'un colis : une ligne par changement d\'étape.'])]
#[ORM\Index(name: 'idx_tracking_order', columns: ['order_id', 'occurred_at'])]
class OrderTrackingEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'trackingEvents')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status;

    /** Précision facultative (« Le coursier Jean vous appellera à l'arrivée »). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** Qui a enregistré l'étape (admin ou fournisseur) — NULL pour l'étape automatique de confirmation. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    public function __construct(Order $order, OrderStatus $status, ?string $description, ?User $createdBy, \DateTimeImmutable $occurredAt)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->status = $status;
        $this->description = $description;
        $this->createdBy = $createdBy;
        $this->occurredAt = $occurredAt;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
