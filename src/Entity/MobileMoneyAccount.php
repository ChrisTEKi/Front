<?php

namespace App\Entity;

use App\Enum\MobileMoneyCarrier;
use App\Repository\MobileMoneyAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Numéro Mobile Money d'un client — table `mobile_money_accounts`.
 *
 * Avant de payer par MVola / Orange Money / Airtel Money, le client doit PROUVER qu'il possède
 * le numéro (code SMS). Une fois `verified_at` renseigné, il n'a plus à le refaire pour ce numéro.
 *
 * NB : la vérification prouve que le client contrôle la ligne mobile. Elle ne remplace pas
 * la vérification faite par l'opérateur lors du débit réel du portefeuille.
 */
#[ORM\Entity(repositoryClass: MobileMoneyAccountRepository::class)]
#[ORM\Table(name: 'mobile_money_accounts', options: ['comment' => 'Numéros Mobile Money des clients (MVola, Orange Money, Airtel Money) et état de leur vérification par SMS.'])]
// Un client ne peut pas enregistrer deux fois le même numéro.
#[ORM\UniqueConstraint(name: 'uniq_mm_user_msisdn', columns: ['user_id', 'msisdn'])]
class MobileMoneyAccount
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Opérateur déduit du préfixe du numéro (034 → MVola, etc.). */
    #[ORM\Column(type: Types::STRING, length: 16, enumType: MobileMoneyCarrier::class)]
    private MobileMoneyCarrier $carrier;

    /** Numéro au format E.164 (ex. +261341234567). */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $msisdn;

    #[ORM\Column(name: 'verified_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(User $user, MobileMoneyCarrier $carrier, string $msisdn, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->carrier = $carrier;
        $this->msisdn = $msisdn;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCarrier(): MobileMoneyCarrier
    {
        return $this->carrier;
    }

    public function getMsisdn(): string
    {
        return $this->msisdn;
    }

    public function isVerified(): bool
    {
        return null !== $this->verifiedAt;
    }

    public function markVerified(\DateTimeImmutable $now): void
    {
        $this->verifiedAt ??= $now;
    }

    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }
}
