<?php

namespace App\Moderation;

/**
 * Résultat d'une opération de remboursement.
 *
 * `remaining` > 0 : le lot maximal a été atteint, il reste des commandes à rembourser — relancer l'opération.
 */
final class RefundReport
{
    public function __construct(
        public readonly int $orders = 0,
        public readonly int $buyers = 0,
        public readonly int $amount = 0,
        public readonly int $remaining = 0,
    ) {
    }

    /** @return array{orders: int, buyers: int, amount: int, remaining: int} */
    public function toArray(): array
    {
        return ['orders' => $this->orders, 'buyers' => $this->buyers, 'amount' => $this->amount, 'remaining' => $this->remaining];
    }
}
