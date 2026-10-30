<?php

namespace App\Enum;

/**
 * Avancement du paiement d'une commande (colonne `orders.payment_status`).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
