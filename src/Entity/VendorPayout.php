<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'vendor_payout')]
class VendorPayout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VendorApplication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?VendorApplication $vendor = null;

    #[ORM\Column]
    private int $grossSales = 0;

    #[ORM\Column]
    private int $commissionAmount = 0;

    #[ORM\Column]
    private int $planFee = 0;

    #[ORM\Column]
    private int $netAmount = 0;

    #[ORM\Column(length: 24)]
    private string $status = 'pending';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodEnd;

    public function __construct()
    {
        $today = new \DateTimeImmutable('today');
        $this->periodStart = $today;
        $this->periodEnd = $today;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVendor(): ?VendorApplication
    {
        return $this->vendor;
    }

    public function setVendor(VendorApplication $vendor): self
    {
        $this->vendor = $vendor;

        return $this;
    }

    public function getGrossSales(): int
    {
        return $this->grossSales;
    }

    public function setGrossSales(int $grossSales): self
    {
        $this->grossSales = $grossSales;

        return $this;
    }

    public function getCommissionAmount(): int
    {
        return $this->commissionAmount;
    }

    public function setCommissionAmount(int $commissionAmount): self
    {
        $this->commissionAmount = $commissionAmount;

        return $this;
    }

    public function getPlanFee(): int
    {
        return $this->planFee;
    }

    public function setPlanFee(int $planFee): self
    {
        $this->planFee = $planFee;

        return $this;
    }

    public function getNetAmount(): int
    {
        return $this->netAmount;
    }

    public function setNetAmount(int $netAmount): self
    {
        $this->netAmount = $netAmount;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): self
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(\DateTimeImmutable $periodEnd): self
    {
        $this->periodEnd = $periodEnd;

        return $this;
    }
}