<?php

namespace App\Order;

use App\Entity\Order;

/** Résultat de OrderService::place() : la commande, et si elle existait déjà (renvoi du même jeton anti-doublon). */
final class OrderPlacement
{
    public function __construct(
        public readonly Order $order,
        /** true = c'est une commande déjà enregistrée avec ce jeton : rien de nouveau n'a été créé. */
        public readonly bool $duplicate = false,
    ) {
    }
}
