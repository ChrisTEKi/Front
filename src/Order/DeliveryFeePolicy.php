<?php

namespace App\Order;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Règle des frais de livraison. Même règle que la maquette : un forfait unique (10 000 Ar) dès qu'il y a au moins un article.
 *
 * Isolée dans sa propre classe pour pouvoir la faire évoluer (par zone, par poids, franco de port…) sans toucher
 * à OrderService. Le montant se règle dans config/services.yaml (paramètre app.order.delivery_fee).
 */
final class DeliveryFeePolicy
{
    public function __construct(
        #[Autowire('%app.order.delivery_fee%')]
        private readonly int $flatFee,
    ) {
    }

    /** Frais de livraison pour un panier d'un sous-total donné (en Ariary). */
    public function feeFor(int $subtotal): int
    {
        return $subtotal > 0 ? $this->flatFee : 0;
    }
}
