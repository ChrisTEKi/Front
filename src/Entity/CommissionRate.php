<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Taux de commission GoBuy d'un rayon — table `commission_rates` (une ligne par rayon).
 *
 * Le taux est un POURCENTAGE des ventes (ex. 7.50 = 7,5 %), conservé en texte décimal (2 décimales) : pas d'arrondi de nombre
 * à virgule en base. Il se modifie depuis l'espace administrateur (page « Commissions », droit « commissions.manage »). Un rayon sans
 * ligne garde son taux d'origine (App\Supplier\CommissionSchedule::CATEGORIES) : la ligne n'est créée qu'au premier changement. Qui a
 * changé quoi, et quand, est aussi écrit au journal d'audit.
 *
 * Le barème reste INFORMATIF tant que les reversements ne sont pas branchés sur les paiements : changer un taux change ce que
 * montrent la page « Devenir partenaire » et l'Espace Fournisseur, pas encore de montant versé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'commission_rates', options: ['comment' => 'Taux de commission (en %) par rayon, modifiable par un administrateur.'])]
class CommissionRate
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 40, options: ['comment' => 'Identifiant du rayon (cat-mode, cat-elec…)'])]
    private string $category;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, options: ['comment' => 'Pourcentage des ventes'])]
    private string $rate;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy;

    public function __construct(string $category, string $rate, \DateTimeImmutable $now, ?User $updatedBy = null)
    {
        $this->category = $category;
        $this->rate = $rate;
        $this->updatedAt = $now;
        $this->updatedBy = $updatedBy;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    /** Pourcentage en texte décimal (« 7.50 »). */
    public function getRate(): string
    {
        return $this->rate;
    }

    public function changeRate(string $rate, \DateTimeImmutable $now, User $by): void
    {
        $this->rate = $rate;
        $this->updatedAt = $now;
        $this->updatedBy = $by;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }
}
