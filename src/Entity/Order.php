<?php

namespace App\Entity;

use App\Enum\Currency;
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
 *  - Les montants `subtotal`, `delivery_fee`, `total` sont en Ariary entiers (l'Ariary n'a pas de centimes en pratique) : c'est
 *    ce qui est DÉBITÉ (Mobile Money en Ariary uniquement) et la base de la comptabilité, des remboursements et des versements.
 *  - Si le client a commandé dans une autre devise (euro, dollar), la commande garde en plus la devise, le TAUX appliqué à ce
 *    moment-là et les montants FIGÉS dans cette devise (`*_charged`) : le prix annoncé au client ne bouge plus ensuite,
 *    même si le taux change. Pour une commande en Ariary ces colonnes restent vides (les montants sont ceux en Ariary).
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

    /** Devise dans laquelle le client a passé la commande (MGA par défaut). */
    #[ORM\Column(type: Types::STRING, length: 3, enumType: Currency::class, options: ['default' => 'MGA', 'comment' => 'Devise de la commande ; les montants subtotal/delivery_fee/total restent en Ariary'])]
    private Currency $currency = Currency::MGA;

    /** Taux appliqué (Ariary pour 1 unité de la devise) au moment de la commande ; NULL pour une commande en Ariary. */
    #[ORM\Column(name: 'exchange_rate', type: Types::DECIMAL, precision: 18, scale: 6, nullable: true, options: ['comment' => 'Ariary pour 1 unité de la devise, figé à la commande'])]
    private ?string $exchangeRate = null;

    /** Total des articles dans la devise de la commande (unités mineures) ; NULL pour une commande en Ariary. */
    #[ORM\Column(name: 'subtotal_charged', type: Types::INTEGER, nullable: true)]
    private ?int $subtotalCharged = null;

    #[ORM\Column(name: 'delivery_fee_charged', type: Types::INTEGER, nullable: true)]
    private ?int $deliveryFeeCharged = null;

    #[ORM\Column(name: 'total_charged', type: Types::INTEGER, nullable: true)]
    private ?int $totalCharged = null;

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

    /** Montant remboursé à l'acheteur par l'équipe GoBuy (Ariary). 0 = aucun remboursement. */
    #[ORM\Column(name: 'refunded_amount', type: Types::INTEGER, options: ['default' => 0, 'comment' => 'Montant remboursé (Ariary) suite à une décision de l\'équipe ; jamais supérieur au total'])]
    private int $refundedAmount = 0;

    #[ORM\Column(name: 'refunded_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

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

    /**
     * Enregistre la devise de la commande, le taux appliqué et les montants figés dans cette devise (unités mineures).
     * Sans effet pour l'Ariary. À appeler à la création, avant l'enregistrement.
     */
    public function setCharged(Currency $currency, string $exchangeRate, int $subtotal, int $deliveryFee): void
    {
        if (Currency::BASE === $currency) {
            return;
        }
        $this->currency = $currency;
        $this->exchangeRate = $exchangeRate;
        $this->subtotalCharged = $subtotal;
        $this->deliveryFeeCharged = $deliveryFee;
        $this->totalCharged = $subtotal + $deliveryFee;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    /** Commande passée dans une autre devise que l'Ariary ? */
    public function isForeignCurrency(): bool
    {
        return Currency::BASE !== $this->currency && null !== $this->totalCharged;
    }

    /** Taux figé (Ariary pour 1 unité), ou NULL pour une commande en Ariary. */
    public function getExchangeRate(): ?string
    {
        return $this->exchangeRate;
    }

    /** Total des articles dans la devise de la commande (en Ariary si la commande est en Ariary). */
    public function getChargedSubtotal(): int
    {
        return $this->subtotalCharged ?? $this->subtotal;
    }

    public function getChargedDeliveryFee(): int
    {
        return $this->deliveryFeeCharged ?? $this->deliveryFee;
    }

    /** Total annoncé au client, dans la devise de la commande (en Ariary si la commande est en Ariary). */
    public function getChargedTotal(): int
    {
        return $this->totalCharged ?? $this->total;
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

    public function getRefundedAmount(): int
    {
        return $this->refundedAmount;
    }

    public function getRefundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function isFullyRefunded(): bool
    {
        return $this->refundedAmount >= $this->total;
    }

    /**
     * Rembourse des lignes de la commande (décision de l'équipe : fournisseur banni, compte clos…). Les lignes déjà remboursées sont
     * ignorées. Quand TOUTES les lignes le sont, les frais de livraison sont remboursés aussi : le total est alors rendu en entier.
     * Une commande non livrée dont tout est remboursé est ANNULÉE ; un paiement déjà encaissé passe à « remboursé ».
     * Retourne le montant remboursé par cet appel (0 si rien à faire).
     *
     * @param list<OrderItem> $items lignes à rembourser (de CETTE commande)
     */
    public function refundItems(array $items, ?User $by, \DateTimeImmutable $now): int
    {
        $before = $this->refundedAmount;
        $lines = 0;
        foreach ($items as $item) {
            if ($item->isRefunded()) {
                continue;
            }
            $item->markRefunded($now);
            $lines += $item->getLineTotal();
        }
        if (0 === $lines) {
            return 0;
        }

        $allRefunded = true;
        foreach ($this->items as $item) {
            $allRefunded = $allRefunded && $item->isRefunded();
        }
        // Toutes les lignes remboursées : les frais de livraison suivent, le total est rendu en entier.
        $this->refundedAmount = $allRefunded ? $this->total : min($this->total, $this->refundedAmount + $lines);
        $this->refundedAt = $now;
        $this->updatedAt = $now;

        if ($allRefunded) {
            if (!\in_array($this->status, [OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
                $this->advanceTo(OrderStatus::Cancelled, null, $by, $now);
            }
            if (PaymentStatus::Paid === $this->paymentStatus) {
                $this->paymentStatus = PaymentStatus::Refunded;
            }
        }

        return $this->refundedAmount - $before;
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
