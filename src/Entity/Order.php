<?php

namespace App\Entity;

use App\Enum\MobileMoneyCarrier;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Commande d'un client — table `orders` (« order » est un mot réservé SQL, d'où le pluriel).
 *
 * Principes :
 *  - Les lignes de la commande (`order_items`) et les événements de suivi (`order_tracking_events`)
 *    sont dans des tables liées.
 *  - Les informations du client (nom, e-mail, téléphone) et l'adresse de livraison sont COPIÉES dans la
 *    commande (« snapshot ») : la commande reste exacte même si le client modifie ensuite son profil,
 *    et reste lisible comptablement après la suppression de son compte (où ces copies sont anonymisées).
 *  - Les montants sont en Ariary entiers (l'Ariary n'a pas de centimes en pratique).
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders', options: ['comment' => 'Commandes des clients. Contient une copie figée des coordonnées client + adresse de livraison (anonymisée à la suppression du compte).'])]
#[ORM\UniqueConstraint(name: 'uniq_orders_number', columns: ['order_number'])]
// Un même client ne peut pas enregistrer deux commandes avec le même jeton anti-doublon (voir Version20261019000000).
#[ORM\UniqueConstraint(name: 'uniq_orders_client_idempotency', columns: ['client_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_orders_client', columns: ['client_id'])]
#[ORM\Index(name: 'idx_orders_status', columns: ['status'])]
#[ORM\Index(name: 'idx_orders_created_at', columns: ['created_at'])]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Numéro public de la commande, ex. « GB-2026-482915 » (affiché au client, dans l'e-mail, utilisé pour le suivi). */
    #[ORM\Column(name: 'order_number', type: Types::STRING, length: 20, options: ['comment' => 'Numéro public, ex. GB-2026-482915'])]
    private string $orderNumber;

    /**
     * Client propriétaire. ON DELETE SET NULL : si le compte est supprimé définitivement,
     * la commande est conservée (obligations comptables) mais n'est plus rattachée à une personne.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $client;

    // ---- Copie des coordonnées du client au moment de la commande (anonymisées à la purge du compte) ----
    #[ORM\Column(name: 'customer_name', type: Types::STRING, length: 150, nullable: true)]
    private ?string $customerName;

    #[ORM\Column(name: 'customer_email', type: Types::STRING, length: 180, nullable: true)]
    private ?string $customerEmail;

    #[ORM\Column(name: 'customer_phone', type: Types::STRING, length: 20, nullable: true)]
    private ?string $customerPhone;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::Confirmed;

    /** Total des articles (somme des lignes). */
    #[ORM\Column(type: Types::INTEGER, options: ['comment' => 'Total des articles en Ariary'])]
    private int $subtotal;

    /** Frais de livraison. */
    #[ORM\Column(name: 'delivery_fee', type: Types::INTEGER)]
    private int $deliveryFee;

    /** Montant total à payer = subtotal + delivery_fee. */
    #[ORM\Column(type: Types::INTEGER, options: ['comment' => 'Montant total en Ariary = subtotal + delivery_fee'])]
    private int $total;

    #[ORM\Column(name: 'payment_method', type: Types::STRING, length: 20, enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod;

    #[ORM\Column(name: 'payment_status', type: Types::STRING, length: 16, enumType: PaymentStatus::class)]
    private PaymentStatus $paymentStatus = PaymentStatus::Pending;

    /** Opérateur Mobile Money utilisé (si paiement par Mobile Money). */
    #[ORM\Column(name: 'payment_carrier', type: Types::STRING, length: 16, nullable: true, enumType: MobileMoneyCarrier::class)]
    private ?MobileMoneyCarrier $paymentCarrier = null;

    /** Numéro Mobile Money débité (E.164) — il a été vérifié par code SMS avant la commande. */
    #[ORM\Column(name: 'payment_msisdn', type: Types::STRING, length: 20, nullable: true)]
    private ?string $paymentMsisdn = null;

    /** Nombre d'échéances : 1 = paiement comptant, 3 ou 4 = paiement fractionné sans frais. */
    #[ORM\Column(name: 'installments_count', type: Types::SMALLINT, options: ['comment' => '1 = comptant, 3 ou 4 = paiement fractionné sans frais', 'default' => 1])]
    private int $installmentsCount = 1;

    /**
     * Adresse de livraison (copie) en JSONB :
     * {fullName, phone, street, city, postalCode, instructions}.
     *
     * @var array<string, string|null>
     */
    #[ORM\Column(name: 'shipping_address', type: Types::JSON, options: ['comment' => 'Copie JSON de l\'adresse de livraison', 'jsonb' => true])]
    private array $shippingAddress;

    /** Coursier assigné (renseigné quand la commande part en livraison). */
    #[ORM\Column(name: 'courier_name', type: Types::STRING, length: 150, nullable: true)]
    private ?string $courierName = null;

    #[ORM\Column(name: 'courier_phone', type: Types::STRING, length: 20, nullable: true)]
    private ?string $courierPhone = null;

    /** Langue du client au moment de la commande (pour les e-mails ultérieurs). */
    #[ORM\Column(type: Types::STRING, length: 5, options: ['default' => 'fr'])]
    private string $locale;

    /**
     * Date d'envoi de l'e-mail de confirmation. NULL = pas (encore) envoyé :
     * la commande `app:orders:send-confirmations` réessaie les envois échoués.
     */
    #[ORM\Column(name: 'confirmation_email_sent_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true, options: ['comment' => 'NULL = e-mail de confirmation pas encore parti (réessayé par app:orders:send-confirmations)'])]
    private ?\DateTimeImmutable $confirmationEmailSentAt = null;

    /** Jeton anti-doublon envoyé par le navigateur (un par tentative de paiement). NULL = commande sans jeton. */
    #[ORM\Column(name: 'idempotency_key', type: Types::STRING, length: 64, nullable: true, options: ['comment' => 'Jeton anti-doublon généré par le navigateur à chaque tentative de paiement (unique par client)'])]
    private ?string $idempotencyKey = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    /** @var Collection<int, OrderTrackingEvent> */
    #[ORM\OneToMany(targetEntity: OrderTrackingEvent::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['occurredAt' => 'ASC'])]
    private Collection $trackingEvents;

    /**
     * @param array<string, string|null> $shippingAddress
     */
    public function __construct(
        string $orderNumber,
        User $client,
        array $shippingAddress,
        PaymentMethod $paymentMethod,
        int $installmentsCount,
        int $subtotal,
        int $deliveryFee,
        string $locale,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->orderNumber = $orderNumber;
        $this->client = $client;
        // Copies figées des coordonnées du client.
        $this->customerName = $client->getFullName();
        $this->customerEmail = $client->getEmail();
        $this->customerPhone = $client->getPhone();
        $this->shippingAddress = $shippingAddress;
        $this->paymentMethod = $paymentMethod;
        $this->installmentsCount = $installmentsCount;
        $this->subtotal = $subtotal;
        $this->deliveryFee = $deliveryFee;
        $this->total = $subtotal + $deliveryFee;
        $this->locale = $locale;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->items = new ArrayCollection();
        $this->trackingEvents = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getClient(): ?User
    {
        return $this->client;
    }

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customerEmail;
    }

    public function getCustomerPhone(): ?string
    {
        return $this->customerPhone;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getSubtotal(): int
    {
        return $this->subtotal;
    }

    public function getDeliveryFee(): int
    {
        return $this->deliveryFee;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getPaymentStatus(): PaymentStatus
    {
        return $this->paymentStatus;
    }

    public function getPaymentCarrier(): ?MobileMoneyCarrier
    {
        return $this->paymentCarrier;
    }

    public function getPaymentMsisdn(): ?string
    {
        return $this->paymentMsisdn;
    }

    public function getInstallmentsCount(): int
    {
        return $this->installmentsCount;
    }

    /** @return array<string, string|null> */
    public function getShippingAddress(): array
    {
        return $this->shippingAddress;
    }

    public function getCourierName(): ?string
    {
        return $this->courierName;
    }

    public function getCourierPhone(): ?string
    {
        return $this->courierPhone;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getConfirmationEmailSentAt(): ?\DateTimeImmutable
    {
        return $this->confirmationEmailSentAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /** @return Collection<int, OrderTrackingEvent> */
    public function getTrackingEvents(): Collection
    {
        return $this->trackingEvents;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(?string $key): void
    {
        $this->idempotencyKey = $key;
    }

    public function addItem(OrderItem $item): void
    {
        $this->items->add($item);
    }

    /** Enregistre le paiement Mobile Money choisi (opérateur + numéro vérifié). */
    public function setMobileMoneyPayment(MobileMoneyCarrier $carrier, string $msisdn): void
    {
        $this->paymentCarrier = $carrier;
        $this->paymentMsisdn = $msisdn;
    }

    public function setPaymentStatus(PaymentStatus $status, \DateTimeImmutable $now): void
    {
        $this->paymentStatus = $status;
        $this->updatedAt = $now;
    }

    public function assignCourier(string $name, ?string $phone, \DateTimeImmutable $now): void
    {
        $this->courierName = $name;
        $this->courierPhone = $phone;
        $this->updatedAt = $now;
    }

    /**
     * Fait avancer la commande à une nouvelle étape ET ajoute l'événement de suivi correspondant.
     * C'est le SEUL moyen de changer le statut : l'historique de suivi reste donc toujours cohérent.
     */
    public function advanceTo(OrderStatus $status, ?string $description, ?User $by, \DateTimeImmutable $now): OrderTrackingEvent
    {
        $this->status = $status;
        $this->updatedAt = $now;
        $event = new OrderTrackingEvent($this, $status, $description, $by, $now);
        $this->trackingEvents->add($event);

        return $event;
    }

    public function markConfirmationEmailSent(\DateTimeImmutable $now): void
    {
        $this->confirmationEmailSentAt = $now;
    }

    /**
     * Anonymise les données personnelles (appelé à la suppression définitive du compte).
     * La commande (montants, articles, dates) est conservée pour la comptabilité.
     */
    public function anonymize(\DateTimeImmutable $now): void
    {
        $this->client = null;
        $this->customerName = null;
        $this->customerEmail = null;
        $this->customerPhone = null;
        $this->paymentMsisdn = null;
        $this->courierPhone = null;
        $this->shippingAddress = ['fullName' => null, 'phone' => null, 'street' => null, 'city' => $this->shippingAddress['city'] ?? null, 'postalCode' => null, 'instructions' => null];
        $this->updatedAt = $now;
    }
}
