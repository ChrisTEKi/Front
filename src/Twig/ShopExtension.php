<?php

namespace App\Twig;

use App\Order\DeliveryFeePolicy;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonction Twig `delivery_fee()` : frais de livraison forfaitaires, en Ariary.
 *
 * Pourquoi : le panier est affiché par le navigateur (il est mémorisé dans le navigateur, voir assets/cart.js) et doit
 * montrer le MÊME forfait que celui que le serveur facturera à la commande. Le montant est donc lu une seule fois,
 * dans la règle officielle (DeliveryFeePolicy, paramètre app.order.delivery_fee), et transmis à la page.
 * Le navigateur ne fait qu'AFFICHER : le serveur recalcule toujours le montant réel de la commande.
 */
final class ShopExtension extends AbstractExtension
{
    public function __construct(private readonly DeliveryFeePolicy $fees)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('delivery_fee', $this->fees->feeFor(...))];
    }
}
