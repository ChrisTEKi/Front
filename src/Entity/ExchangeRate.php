<?php

namespace App\Entity;

use App\Enum\Currency;
use App\Repository\ExchangeRateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Taux de change — table `exchange_rates`.
 *
 * Chaque ligne est un taux ENREGISTRÉ à un instant donné ; on n'en modifie jamais : on en ajoute une nouvelle (l'historique
 * sert de preuve : « à quel taux cette commande a-t-elle été convertie ? »). Le taux en vigueur d'une devise est sa ligne la
 * plus récente.
 *
 * Le taux est exprimé en ARIARY POUR UNE UNITÉ de la devise (ex. 1 EUR = 5 000,000000 Ar). L'Ariary (devise de base) n'a pas de taux.
 *
 * Origine : « auto » (récupéré automatiquement par la tâche planifiée) ou « manual » (saisi par un administrateur). Un taux
 * manuel BLOQUE les mises à jour automatiques tant qu'il est valable (`valid_until`, ou sans limite si NULL) ou qu'un
 * administrateur n'a pas « repris le mode automatique ».
 */
#[ORM\Entity(repositoryClass: ExchangeRateRepository::class)]
#[ORM\Table(name: 'exchange_rates', options: ['comment' => 'Historique des taux de change (Ariary pour 1 unité de la devise).'])]
#[ORM\Index(name: 'idx_exchange_rates_currency_created', columns: ['currency', 'created_at'])]
class ExchangeRate
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 3, enumType: Currency::class)]
    private Currency $currency;

    /** Ariary pour 1 unité de la devise, en texte décimal (6 décimales) : pas d'arrondi de nombre à virgule en base. */
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6, options: ['comment' => 'Ariary pour 1 unité de la devise'])]
    private string $rate;

    #[ORM\Column(type: Types::STRING, length: 8)]
    private string $source;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $note;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Taux manuel seulement : fin de validité (NULL = jusqu'à ce qu'un administrateur reprenne le mode automatique). */
    #[ORM\Column(name: 'valid_until', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil;

    public function __construct(Currency $currency, string $rate, string $source, \DateTimeImmutable $now, ?User $createdBy = null, ?string $note = null, ?\DateTimeImmutable $validUntil = null)
    {
        if (Currency::BASE === $currency) {
            throw new \InvalidArgumentException('L\'Ariary est la devise de base : elle n\'a pas de taux.');
        }
        $this->id = Uuid::v7();
        $this->currency = $currency;
        $this->rate = $rate;
        $this->source = $source;
        $this->createdAt = $now;
        $this->createdBy = $createdBy;
        $this->note = $note;
        $this->validUntil = self::SOURCE_MANUAL === $source ? $validUntil : null;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    /** Ariary pour 1 unité, texte décimal (« 5000.000000 »). */
    public function getRate(): string
    {
        return $this->rate;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function isManual(): bool
    {
        return self::SOURCE_MANUAL === $this->source;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    /** Vrai si ce taux manuel interdit encore les mises à jour automatiques. */
    public function locksAutomaticUpdates(\DateTimeImmutable $now): bool
    {
        return $this->isManual() && (null === $this->validUntil || $this->validUntil > $now);
    }
}
