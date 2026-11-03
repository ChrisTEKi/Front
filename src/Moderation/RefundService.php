<?php

namespace App\Moderation;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\Order;
use App\Entity\User;
use App\Http\DeferredWork;
use App\Notification\ModerationNotifier;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Remboursement des acheteurs lésés par un compte sanctionné.
 *
 *  - FOURNISSEUR sanctionné : toutes les lignes qu'il a vendues sont remboursées — les commandes en cours (`open`) ou aussi les
 *    commandes déjà livrées (`all`). Si la commande ne contient que ses articles, elle est annulée et le total (frais de livraison
 *    compris) est rendu ; sinon seules ses lignes sont remboursées et les autres articles suivent leur cours.
 *  - CLIENT banni : ses commandes en cours sont annulées et remboursées.
 *
 * ÉTAT DES PAIEMENTS : aucune passerelle de paiement (MVola, Orange Money, Airtel Money, carte) n'est encore raccordée. Un
 * remboursement ENREGISTRE donc la décision (montant remboursé, ligne marquée, commande annulée, paiement « remboursé » s'il avait
 * été encaissé) et PRÉVIENT l'acheteur par e-mail ; le virement lui-même reste à faire par l'équipe financière (le journal d'audit
 * liste les commandes concernées). Voir README, « Ce qui reste à raccorder ».
 *
 * Un appel traite au plus BATCH commandes (durée de la requête bornée) ; `remaining` indique s'il faut relancer.
 */
final class RefundService
{
    public const BATCH = 500;
    public const SCOPES = ['open', 'all'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit,
        private readonly ModerationNotifier $notifier,
        private readonly DeferredWork $deferred,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string $scope « open » (commandes en cours) ou « all » (en cours + déjà livrées)
     *
     * @throws ModerationException « not_a_supplier », « invalid_scope », « reason_required », « nothing_to_refund »
     */
    public function refundSupplierBuyers(User $admin, User $supplier, string $scope, string $reason): RefundReport
    {
        if (!$supplier->isSupplier()) {
            throw new ModerationException('not_a_supplier');
        }
        if (!\in_array($scope, self::SCOPES, true)) {
            throw new ModerationException('invalid_scope');
        }
        $reason = $this->reason($reason);
        $includeDelivered = 'all' === $scope;

        $orders = $this->orders->findWithItems($this->orders->idsRefundableForSupplier($supplier, $includeDelivered, self::BATCH));
        $now = $this->clock->now();
        $supplierId = $supplier->getId();

        /** @var array<string, array{user: User, numbers: list<string>, amount: int}> $buyers */
        $buyers = [];
        $numbers = [];
        $total = 0;
        $this->em->wrapInTransaction(function () use ($orders, $supplierId, $admin, $now, &$buyers, &$numbers, &$total): void {
            foreach ($orders as $order) {
                $lines = [];
                foreach ($order->getItems() as $item) {
                    if (!$item->isRefunded() && $item->getSupplier()?->getId()->equals($supplierId)) {
                        $lines[] = $item;
                    }
                }
                $amount = $order->refundItems($lines, $admin, $now);
                if ($amount > 0) {
                    $numbers[] = $order->getOrderNumber();
                    $total += $amount;
                    $this->collect($buyers, $order, $amount);
                }
            }
            $this->em->flush();
        });

        $report = new RefundReport(\count($numbers), \count($buyers), $total, $this->orders->countRefundableForSupplier($supplier, $includeDelivered));
        if (0 === $report->orders) {
            throw new ModerationException('nothing_to_refund');
        }

        $this->audit->record(AuditAction::REFUNDS_ISSUED, $admin, 'user', $supplierId->toRfc4122(), [
            'scope' => $scope, 'reason' => $reason, 'orders' => $numbers, 'amount' => $total, 'remaining' => $report->remaining,
        ]);
        $this->tellBuyers($buyers);

        return $report;
    }

    /**
     * Annule et rembourse les commandes EN COURS d'un client (utilisé quand son compte est banni). Retourne un rapport vide s'il n'y en a pas.
     */
    public function refundClientOpenOrders(User $admin, User $client, string $reason): RefundReport
    {
        $orders = $this->orders->findOpenForClient($client, self::BATCH + 1);
        $now = $this->clock->now();

        $numbers = [];
        $total = 0;
        $this->em->wrapInTransaction(function () use ($orders, $admin, $now, &$numbers, &$total): void {
            foreach (\array_slice($orders, 0, self::BATCH) as $order) {
                $amount = $order->refundItems($order->getItems()->toArray(), $admin, $now);
                if ($amount > 0) {
                    $numbers[] = $order->getOrderNumber();
                    $total += $amount;
                }
            }
            $this->em->flush();
        });

        if ([] !== $numbers) {
            $this->audit->record(AuditAction::REFUNDS_ISSUED, $admin, 'user', $client->getId()->toRfc4122(), [
                'scope' => 'client_open', 'reason' => $this->reason($reason), 'orders' => $numbers, 'amount' => $total,
            ]);
        }

        return new RefundReport(\count($numbers), [] === $numbers ? 0 : 1, $total, max(0, \count($orders) - self::BATCH));
    }

    /**
     * @param array<string, array{user: User, numbers: list<string>, amount: int}> $buyers
     */
    private function collect(array &$buyers, Order $order, int $amount): void
    {
        $client = $order->getClient();
        if (null === $client) {
            return; // compte déjà supprimé : personne à prévenir
        }
        $key = $client->getId()->toRfc4122();
        $buyers[$key] ??= ['user' => $client, 'numbers' => [], 'amount' => 0];
        $buyers[$key]['numbers'][] = $order->getOrderNumber();
        $buyers[$key]['amount'] += $amount;
    }

    /**
     * Un e-mail PAR acheteur (même s'il a plusieurs commandes), envoyé après la réponse : un lot de 500 ne fait pas attendre l'administrateur.
     *
     * @param array<string, array{user: User, numbers: list<string>, amount: int}> $buyers
     */
    private function tellBuyers(array $buyers): void
    {
        if ([] === $buyers) {
            return;
        }
        $this->deferred->defer(function () use ($buyers): void {
            foreach ($buyers as $buyer) {
                $this->notifier->notify($buyer['user'], 'order_refunded', [
                    '%orders%' => implode(', ', $buyer['numbers']),
                    '%amount%' => number_format($buyer['amount'], 0, ',', "\u{202F}").' Ar',
                ]);
            }
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ModerationException('reason_required');
        }

        return mb_substr($reason, 0, 500);
    }
}
