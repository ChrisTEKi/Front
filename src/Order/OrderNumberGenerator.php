<?php

namespace App\Order;

use App\Repository\OrderRepository;
use Psr\Clock\ClockInterface;

/**
 * Fabrique le numéro public d'une commande : « GB-2026-482915 » (GB + année + 6 chiffres aléatoires).
 *
 * Aléatoire (et non « 1, 2, 3… ») : un numéro séquentiel révélerait le volume de ventes et se devinerait d'un clic.
 * Le numéro n'est de toute façon jamais une clé d'accès : voir OrderRepository::findForClientByReference
 * (la commande n'est consultable que par son propriétaire connecté).
 */
final class OrderNumberGenerator
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ClockInterface $clock,
    ) {
    }

    public function next(): string
    {
        $year = $this->clock->now()->format('Y');
        // 900 000 combinaisons par an ; quelques essais suffisent largement pour éviter une collision.
        for ($try = 0; $try < 10; ++$try) {
            $candidate = \sprintf('GB-%s-%06d', $year, random_int(100000, 999999));
            if (!$this->orders->numberExists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Impossible de générer un numéro de commande unique.');
    }
}
