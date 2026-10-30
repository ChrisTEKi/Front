<?php

namespace App\Entity;

use App\Repository\AddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Adresse de livraison enregistrée par un client — table `addresses`
 * (gérée dans « Mon compte › Mes adresses », proposée à la commande).
 *
 * Un client peut en garder plusieurs (10 au maximum, voir AddressBook). UNE SEULE est « par défaut » : c'est elle qui est
 * préremplie à la commande tant que le client n'en choisit pas une autre. L'unicité est garantie par la base elle-même
 * (index unique partiel `uniq_addresses_default`), pas seulement par le code.
 */
#[ORM\Entity(repositoryClass: AddressRepository::class)]
#[ORM\Table(name: 'addresses', options: ['comment' => 'Adresses de livraison enregistrées par les clients.'])]
#[ORM\Index(name: 'idx_addresses_user', columns: ['user_id'])]
// Au plus UNE adresse par défaut par client (index unique PARTIEL : il ne concerne que les lignes is_default = true).
#[ORM\UniqueConstraint(name: 'uniq_addresses_default', columns: ['user_id'], options: ['where' => '(is_default = true)'])]
class Address
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Nom du destinataire. */
    #[ORM\Column(name: 'full_name', type: Types::STRING, length: 150)]
    private string $fullName;

    /**
     * Téléphone du destinataire (E.164) pour que le coursier puisse l'appeler. Facultatif ici : une adresse saisie à
     * l'inscription n'a pas toujours de téléphone (il est de toute façon exigé, et vérifié, au moment de commander).
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $phone;

    /** Rue, lot, quartier. */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $street;

    /** Ville / commune (ex. Antananarivo, Tamatave…). */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $city;

    /** Code postal (ex. 101 pour Antananarivo centre), facultatif. */
    #[ORM\Column(name: 'postal_code', type: Types::STRING, length: 10, nullable: true)]
    private ?string $postalCode = null;

    /** Consignes au coursier (code du portail, repère…). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions = null;

    /** Nom donné par le client pour s'y retrouver (« Maison », « Bureau »…), facultatif. */
    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $label = null;

    /** Adresse proposée en premier à la commande. Une seule par client (index unique partiel en base). */
    #[ORM\Column(name: 'is_default', type: Types::BOOLEAN, options: ['default' => false, 'comment' => 'Adresse préremplie à la commande (une seule par client)'])]
    private bool $isDefault = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $fullName, ?string $phone, string $street, string $city, ?string $postalCode, ?string $instructions, \DateTimeImmutable $now, ?string $label = null, bool $isDefault = false)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->fullName = $fullName;
        $this->phone = $phone;
        $this->street = $street;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->instructions = $instructions;
        $this->label = $label;
        $this->isDefault = $isDefault;
        $this->createdAt = $now;
    }

    /** Remplace le contenu de l'adresse (modification faite par le client dans « Mes adresses »). */
    public function update(string $fullName, ?string $phone, string $street, string $city, ?string $postalCode, ?string $instructions, ?string $label): void
    {
        $this->fullName = $fullName;
        $this->phone = $phone;
        $this->street = $street;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->instructions = $instructions;
        $this->label = $label;
    }

    public function markDefault(bool $default): void
    {
        $this->isDefault = $default;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
