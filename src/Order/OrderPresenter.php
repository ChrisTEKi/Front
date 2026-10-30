<?php

namespace App\Order;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Enum\PaymentStatus;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Prépare une commande pour l'AFFICHAGE (historique du compte, page de suivi de colis).
 *
 * Les gabarits Twig reçoivent des tableaux simples, déjà traduits, au lieu de manipuler l'entité : toute la logique
 * d'affichage (étapes de suivi, échéancier) vit ici, une seule fois, et les gabarits restent lisibles.
 */
final class OrderPresenter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Ligne de l'historique des commandes.
     *
     * @return array<string, mixed>
     */
    public function summary(Order $order): array
    {
        return [
            'orderNumber' => $order->getOrderNumber(),
            'createdAt' => $order->getCreatedAt(),
            'total' => $order->getTotal(),
            'itemCount' => array_sum(array_map(static fn ($i): int => $i->getQuantity(), $order->getItems()->toArray())),
            'status' => $order->getStatus()->value,
            'statusLabel' => $this->translator->trans('status.'.$order->getStatus()->value, [], 'orders'),
            'paymentLabel' => $this->paymentLabel($order),
        ];
    }

    /**
     * Détail complet : articles, paiement, échéancier, livreur, étapes de suivi.
     *
     * @return array<string, mixed>
     */
    public function detail(Order $order): array
    {
        return $this->summary($order) + [
            'subtotal' => $order->getSubtotal(),
            'deliveryFee' => $order->getDeliveryFee(),
            // « installments » = paiement en plusieurs fois ; sinon la valeur de l'enum.
            'paymentMethod' => $order->getInstallmentsCount() > 1 ? 'installments' : $order->getPaymentMethod()->value,
            'paymentStatus' => $order->getPaymentStatus()->value,
            'paymentStatusLabel' => $this->translator->trans('payment.status.'.$order->getPaymentStatus()->value, [], 'orders'),
            'paymentDetails' => [
                'installmentsCount' => $order->getInstallmentsCount(),
                'carrier' => $order->getPaymentCarrier()?->label(),
                'schedule' => $order->getInstallmentsCount() > 1 ? $this->schedule($order) : [],
            ],
            'address' => $order->getShippingAddress(),
            'courierName' => $order->getCourierName(),
            'courierPhone' => $order->getCourierPhone(),
            'items' => array_map(static fn ($i): array => [
                'name' => $i->getProductName(),
                'quantity' => $i->getQuantity(),
                'unitPrice' => $i->getUnitPrice(),
                'lineTotal' => $i->getLineTotal(),
            ], $order->getItems()->toArray()),
            'trackingSteps' => $this->trackingSteps($order),
        ];
    }

    /**
     * Étapes du suivi de colis.
     *
     * Le statut de la commande est la DERNIÈRE étape franchie. Exemple « préparation » :
     *   Confirmée ✓   Préparation ✓   En livraison ⏳ (étape en cours)   Livrée ○
     *
     * @return list<array<string, mixed>>
     */
    private function trackingSteps(Order $order): array
    {
        // Dernier événement enregistré pour chaque étape (date + commentaire éventuel).
        $events = [];
        foreach ($order->getTrackingEvents() as $event) {
            $events[$event->getStatus()->value] = $event;
        }

        $status = $order->getStatus();
        $steps = [];
        $reached = $status->position();

        foreach (OrderStatus::timeline() as $step) {
            $event = $events[$step->value] ?? null;
            // Commande annulée : une étape est « franchie » seulement si un événement existe pour elle.
            $completed = OrderStatus::Cancelled === $status ? null !== $event : $step->position() <= $reached;
            $steps[] = [
                'status' => $step->value,
                'label' => $this->translator->trans('status.'.$step->value, [], 'orders'),
                'completed' => $completed,
                // L'étape « en cours » est la première non franchie (sauf si la commande est terminée/annulée).
                'current' => false,
                'date' => $event?->getOccurredAt(),
                'description' => $completed ? ($event?->getDescription() ?? $this->translator->trans('tracking.description.'.$step->value, [], 'orders')) : null,
            ];
        }

        if (OrderStatus::Cancelled === $status) {
            $cancelled = $events[OrderStatus::Cancelled->value] ?? null;
            $steps[] = [
                'status' => 'cancelled',
                'label' => $this->translator->trans('status.cancelled', [], 'orders'),
                'completed' => true,
                'current' => false,
                'cancelled' => true,
                'date' => $cancelled?->getOccurredAt(),
                'description' => $cancelled?->getDescription() ?? $this->translator->trans('tracking.description.cancelled', [], 'orders'),
            ];

            return $steps;
        }

        // Étape en cours = la suivante, tant que la commande n'est pas livrée.
        if ($reached >= 0 && $reached < \count($steps) - 1) {
            $steps[$reached + 1]['current'] = true;
        }

        return $steps;
    }

    /**
     * Échéancier d'un paiement en 3x/4x : première échéance le jour de la commande, puis une par mois.
     * La dernière échéance absorbe l'arrondi : la somme des échéances est EXACTEMENT égale au total.
     *
     * NB : l'état « payé » n'est connu que globalement (payment_status). Seule la première échéance est marquée payée
     * quand la commande est « payée » ; l'encaissement échéance par échéance relève de la passerelle de paiement.
     *
     * @return list<array<string, mixed>>
     */
    private function schedule(Order $order): array
    {
        $n = $order->getInstallmentsCount();
        $base = intdiv($order->getTotal(), $n);
        $remainder = $order->getTotal() - $base * $n;

        $rows = [];
        for ($i = 1; $i <= $n; ++$i) {
            $rows[] = [
                'stepIndex' => $i,
                'label' => 1 === $i
                    ? $this->translator->trans('tracking.today', [], 'orders')
                    : $this->translator->trans('tracking.nth_debit', ['%n%' => $i], 'orders'),
                'dueDate' => $order->getCreatedAt()->modify('+'.($i - 1).' months'),
                'amount' => $base + ($i === $n ? $remainder : 0),
                'isPaid' => 1 === $i && PaymentStatus::Paid === $order->getPaymentStatus(),
            ];
        }

        return $rows;
    }

    private function paymentLabel(Order $order): string
    {
        if ($order->getInstallmentsCount() > 1) {
            return $this->translator->trans('payment.installments', ['%count%' => $order->getInstallmentsCount()], 'orders');
        }

        return $this->translator->trans('payment.'.$order->getPaymentMethod()->value, ['%carrier%' => $order->getPaymentCarrier()?->label() ?? ''], 'orders');
    }
}
