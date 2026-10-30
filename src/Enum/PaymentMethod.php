<?php

namespace App\Enum;

/**
 * Moyen de paiement choisi pour une commande (colonne `orders.payment_method`).
 * Le paiement en 3x/4x est exprimé par `orders.installments_count` (1 = paiement comptant).
 */
enum PaymentMethod: string
{
    /** MVola / Orange Money / Airtel Money (l'opérateur est dans `orders.payment_carrier`). */
    case MobileMoney = 'mobile_money';

    /** Carte bancaire Visa / Mastercard. */
    case Card = 'card';

    /** Espèces à la livraison. */
    case CashOnDelivery = 'cash_on_delivery';
}
