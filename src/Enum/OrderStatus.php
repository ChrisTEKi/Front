<?php

namespace App\Enum;

/**
 * Étapes de la vie d'une commande (colonnes `orders.status` et `order_tracking_events.status`).
 *
 * Les 4 premières correspondent à la chronologie de suivi de la maquette :
 *   1. Commande confirmée → 2. En préparation → 3. Coursier en route → 4. Livrée
 */
enum OrderStatus: string
{
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Étapes "normales" dans l'ordre (sans l'annulation) : sert à dessiner la frise de suivi.
     *
     * @return list<self>
     */
    public static function timeline(): array
    {
        return [self::Confirmed, self::Preparing, self::OutForDelivery, self::Delivered];
    }

    /** Position de l'étape dans la frise (0 = première), ou -1 pour une annulation. */
    public function position(): int
    {
        $index = array_search($this, self::timeline(), true);

        return false === $index ? -1 : $index;
    }
}
