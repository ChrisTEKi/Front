<?php

namespace App\Order;

use App\Account\AddressBook;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Http\DeferredWork;
use App\Payment\MobileMoneyService;
use App\Phone\MobileMoneyNumberValidator;
use App\Phone\PhoneNumberParser;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Création d'une commande — LE SEUL endroit où une commande naît.
 *
 * Toutes les règles métier sont ici, pas dans le contrôleur ni dans le JavaScript (qu'un client malveillant peut contourner) :
 *
 *   1. Anti-doublon        : même jeton d'idempotence = même commande (double-clic, renvoi réseau) ;
 *   2. Compte autorisé     : client actif (ni fournisseur, ni admin, ni compte suspendu) ;
 *   3. Téléphone VÉRIFIÉ   : obligatoire avant toute commande (livreur, suivi, prélèvements) ;
 *   4. Panier              : 1 à 50 lignes, quantités 1 à 99 ; nom/prix/fournisseur relus dans le catalogue du SERVEUR ;
 *   5. Paiement            : 3x/4x seulement 3 ou 4 ; paiement Mobile Money seulement sur un numéro VÉRIFIÉ par SMS ;
 *   6. Frais de livraison  : calculés par DeliveryFeePolicy ;
 *   7. Enregistrement      : commande + lignes + premier événement de suivi, dans UNE transaction ;
 *   8. E-mail de confirmation : envoyé après la réponse (kernel.terminate) ; s'il échoue, la commande reste valable et
 *                               la commande `app:orders:send-confirmations` le renverra.
 *
 * ÉTAT DU PAIEMENT : aucune passerelle de paiement (MVola, Orange Money, Airtel Money, carte) n'est encore raccordée.
 * La commande est donc créée avec payment_status = « pending » ; c'est la passerelle (ou un admin financier) qui la passera
 * à « paid ». Voir README, section « Ce qui reste à raccorder ».
 */
final class OrderService
{
    public const MAX_LINES = 50;
    public const MAX_QUANTITY = 99;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderRepository $orders,
        private readonly AddressBook $addressBook,
        private readonly UserRepository $users,
        private readonly CatalogInterface $catalog,
        private readonly DeliveryFeePolicy $deliveryFees,
        private readonly OrderNumberGenerator $numbers,
        private readonly MobileMoneyNumberValidator $mobileMoneyValidator,
        private readonly MobileMoneyService $mobileMoney,
        private readonly PhoneNumberParser $phoneParser,
        private readonly OrderConfirmationMailer $confirmations,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly DeferredWork $deferred,
        private readonly RateLimiterFactoryInterface $orderPlaceLimiter,
    ) {
    }

    /**
     * @throws OrderRefusedException si une règle métier n'est pas respectée
     */
    public function place(User $client, PlaceOrderRequest $request, string $locale): OrderPlacement
    {
        // 1) Anti-doublon : si ce jeton a déjà donné une commande, on la renvoie telle quelle.
        if ('' !== $request->idempotencyKey) {
            $existing = $this->orders->findOneBy(['client' => $client, 'idempotencyKey' => $request->idempotencyKey]);
            if (null !== $existing) {
                return new OrderPlacement($existing, true);
            }
        }

        // 2) Compte autorisé à commander.
        if (!$client->isClient() || !$client->isActive()) {
            throw new OrderRefusedException('account_not_allowed');
        }

        // 3) Téléphone mobile valide ET vérifié par SMS : règle métier obligatoire avant toute commande.
        if (!$client->isPhoneVerified()) {
            throw new OrderRefusedException('phone_not_verified');
        }

        // 4) Panier relu côté serveur.
        [$lines, $subtotal] = $this->priceLines($request->items);

        // 5) Paiement.
        $method = $request->paymentMethod();
        $installments = $request->installmentsCount();
        if (!\in_array($installments, [1, 3, 4], true) || ('installments' === $request->method && !\in_array($installments, [3, 4], true))) {
            throw new OrderRefusedException('invalid_installments');
        }
        $momoAccount = null;
        $momoCheck = null;
        if (PaymentMethod::MobileMoney === $method) {
            $momoCheck = $this->mobileMoneyValidator->validate($request->mobileMoneyNumber);
            if (!$momoCheck->valid) {
                throw new OrderRefusedException('mobile_money_invalid');
            }
            // Numéro non vérifié par SMS (ou appartenant à quelqu'un d'autre) : on refuse AVANT de créer quoi que ce soit.
            $momoAccount = $this->mobileMoney->ensureVerified($client, $momoCheck);
            if (null === $momoAccount) {
                throw new OrderRefusedException('mobile_money_not_verified');
            }
        }

        // 6) Adresse de livraison (copie figée dans la commande). Téléphone du destinataire : celui saisi, sinon celui du compte.
        $recipientPhone = '' !== $request->phone ? $this->phoneParser->parse($request->phone)?->e164 : $client->getPhone();
        $address = [
            'fullName' => $request->fullName,
            'phone' => $recipientPhone,
            'street' => $request->street,
            'city' => $request->city,
            'postalCode' => '' !== $request->postalCode ? $request->postalCode : null,
            'instructions' => '' !== $request->courierNote ? $request->courierNote : null,
        ];

        // Plafond de commandes par client. Contrôlé en DERNIER, une fois tout validé : une saisie erronée ne consomme pas le quota.
        $limit = $this->orderPlaceLimiter->create($client->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            $wait = max(60, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

            throw new OrderRefusedException('too_many_orders', ['%minutes%' => (int) ceil($wait / 60)]);
        }

        $now = $this->clock->now();
        $fee = $this->deliveryFees->feeFor($subtotal);

        // 7) Enregistrement atomique. Le verrou « advisory » PostgreSQL (libéré à la fin de la transaction) fait attendre une
        //    requête jumelle portant le même jeton ; elle verra alors la commande déjà créée au lieu d'en créer une seconde.
        $placement = $this->em->wrapInTransaction(function () use ($client, $request, $lines, $subtotal, $fee, $method, $installments, $momoAccount, $address, $locale, $now): OrderPlacement {
            if ('' !== $request->idempotencyKey) {
                $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:k))', ['k' => $client->getId()->toRfc4122().'|'.$request->idempotencyKey]);
                $existing = $this->orders->findOneBy(['client' => $client, 'idempotencyKey' => $request->idempotencyKey]);
                if (null !== $existing) {
                    return new OrderPlacement($existing, true);
                }
            }

            $order = new Order($this->numbers->next(), $client, $address, $method, $installments, $subtotal, $fee, $locale, $now);
            if ('' !== $request->idempotencyKey) {
                $order->setIdempotencyKey($request->idempotencyKey);
            }
            if (null !== $momoAccount) {
                $order->setMobileMoneyPayment($momoAccount->getCarrier(), $momoAccount->getMsisdn());
                $momoAccount->markUsed($now);
            }
            foreach ($lines as $line) {
                $supplier = null !== $line['item']->supplierId ? $this->users->find($line['item']->supplierId) : null;
                $order->addItem(new OrderItem($order, $line['item']->ref, $line['item']->name, $line['item']->unitPrice, $line['quantity'], $supplier));
            }
            // Premier événement du suivi de colis : « Commande confirmée ».
            $order->advanceTo(OrderStatus::Confirmed, null, null, $now);
            $this->em->persist($order);
            $this->addressBook->remember($client, $address, $request->saveAddress, $request->makeDefaultAddress);

            return new OrderPlacement($order);
        });

        if ($placement->duplicate) {
            return $placement;
        }

        $this->audit->record(AuditAction::ORDER_PLACED, $client, 'order', $placement->order->getId()->toRfc4122(), [
            'number' => $placement->order->getOrderNumber(),
            'total' => $placement->order->getTotal(),
            'method' => $method->value,
            'installments' => $installments,
        ]);

        // 8) E-mail de confirmation après la réponse HTTP (le client n'attend pas le serveur d'e-mails).
        $orderId = $placement->order->getId();
        $this->deferred->defer(function () use ($orderId): void {
            $order = $this->orders->find($orderId);
            if (null !== $order) {
                $this->confirmations->send($order);
            }
        });

        return $placement;
    }

    /**
     * Fait avancer une commande (préparation, en livraison, livrée, annulée) et journalise.
     * Appelé par l'espace admin (permission « orders.update_tracking »).
     */
    public function advance(Order $order, OrderStatus $status, ?string $description, User $by): void
    {
        if ($order->getStatus() === $status) {
            return;
        }
        // Une commande livrée ou annulée est terminée : pas de retour en arrière (l'historique de suivi resterait incohérent).
        if (\in_array($order->getStatus(), [OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
            throw new OrderRefusedException('order_closed');
        }
        // Une étape ne peut qu'avancer (sauf annulation, possible tant que la commande n'est pas terminée).
        if (OrderStatus::Cancelled !== $status && $status->position() < $order->getStatus()->position()) {
            throw new OrderRefusedException('order_backwards');
        }

        $from = $order->getStatus();
        $order->advanceTo($status, '' !== trim((string) $description) ? trim((string) $description) : null, $by, $this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::ORDER_STATUS_CHANGED, $by, 'order', $order->getId()->toRfc4122(), [
            'number' => $order->getOrderNumber(),
            'from' => $from->value,
            'to' => $status->value,
        ]);
    }

    /**
     * Assigne (ou change) le livreur affiché dans le suivi de colis. Appelé par l'espace admin (« orders.update_tracking »).
     *
     * @throws OrderRefusedException si la commande est terminée
     */
    public function assignCourier(Order $order, string $name, ?string $phone, User $by): void
    {
        if (\in_array($order->getStatus(), [OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
            throw new OrderRefusedException('order_closed');
        }
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            throw new OrderRefusedException('invalid_courier');
        }
        $parsed = null !== $phone && '' !== trim($phone) ? $this->phoneParser->parse($phone) : null;
        if (null !== $phone && '' !== trim($phone) && null === $parsed) {
            throw new OrderRefusedException('invalid_courier');
        }

        $order->assignCourier($name, $parsed?->e164, $this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::ORDER_STATUS_CHANGED, $by, 'order', $order->getId()->toRfc4122(), [
            'number' => $order->getOrderNumber(),
            'operation' => 'courier_assigned',
        ]);
    }

    /**
     * Relit chaque ligne du panier dans le catalogue du serveur et calcule le sous-total.
     * Les doublons (même produit deux fois) sont additionnés.
     *
     * @param list<array{ref: string, quantity: int}> $items
     *
     * @return array{0: list<array{item: CatalogItem, quantity: int}>, 1: int}
     */
    private function priceLines(array $items): array
    {
        if ([] === $items) {
            throw new OrderRefusedException('empty_cart');
        }

        $quantities = [];
        foreach ($items as $line) {
            if ('' === $line['ref'] || $line['quantity'] < 1 || $line['quantity'] > self::MAX_QUANTITY) {
                throw new OrderRefusedException('invalid_quantity');
            }
            $quantities[$line['ref']] = ($quantities[$line['ref']] ?? 0) + $line['quantity'];
        }
        if (\count($quantities) > self::MAX_LINES) {
            throw new OrderRefusedException('too_many_lines');
        }

        $lines = [];
        $subtotal = 0;
        foreach ($quantities as $ref => $quantity) {
            $item = $this->catalog->find((string) $ref);
            if (null === $item) {
                throw new OrderRefusedException('unknown_product');
            }
            if (!$item->available) {
                throw new OrderRefusedException('product_unavailable', ['%name%' => $item->name]);
            }
            if ($quantity > self::MAX_QUANTITY) {
                throw new OrderRefusedException('invalid_quantity');
            }
            $lines[] = ['item' => $item, 'quantity' => $quantity];
            $subtotal += $item->unitPrice * $quantity;
        }

        return [$lines, $subtotal];
    }
}
