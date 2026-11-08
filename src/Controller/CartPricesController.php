<?php

namespace App\Controller;

use App\Currency\CurrencyContext;
use App\Currency\ExchangeRates;
use App\Enum\Currency;
use App\Order\CatalogInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prix ACTUELS des produits d'un panier, pour que le navigateur remette son affichage à jour.
 *
 * Le panier est gardé dans le navigateur avec une copie des prix prise à l'ajout. Entre-temps le vendeur peut avoir changé
 * son tarif, ou le taux de change avoir bougé : la page de commande interroge donc cette route à l'ouverture (et après un
 * refus « price_changed ») pour afficher le total que le serveur calculera vraiment.
 *
 *   GET /panier/prix?refs=<ref>,<ref>   (50 produits au plus ; route publique, lecture seule)
 *   → { "ok": true,
 *       "currency": { "current": "EUR", "rates": { "EUR": 5000, "USD": 4500 } },
 *       "items": { "<ref>": { "name": "…", "available": true, "price": 120000, "prices": { "MGA": 120000, "EUR": 2400 }, "overrides": ["EUR"] } } }
 *
 * Un produit inconnu n'apparaît pas ; un produit retiré de la vente apparaît avec « available »: false.
 */
final class CartPricesController extends AbstractController
{
    private const MAX_REFS = 50;

    #[Route('/panier/prix', name: 'app_cart_prices', methods: ['GET'])]
    public function prices(Request $request, CatalogInterface $catalog, ExchangeRates $rates, CurrencyContext $context): JsonResponse
    {
        // « refs[]=… » (un tableau) est ignoré au lieu de provoquer une erreur 400.
        $raw = $request->query->all()['refs'] ?? '';
        $refs = [];
        foreach (explode(',', \is_string($raw) ? $raw : '') as $ref) {
            $ref = trim($ref);
            if ('' !== $ref && \strlen($ref) <= 64) {
                $refs[$ref] = true;
            }
            if (\count($refs) >= self::MAX_REFS) {
                break;
            }
        }

        $items = [];
        foreach (array_keys($refs) as $ref) {
            $item = $catalog->find((string) $ref);
            if (null === $item) {
                continue;
            }
            $prices = [];
            foreach ($context->options() as $currency) {
                $price = $rates->priceIn($item->unitPrice, $item->priceOverrides, $currency);
                if (null !== $price) {
                    $prices[$currency->value] = $price;
                }
            }
            $overrides = array_values(array_filter(
                array_keys($item->priceOverrides),
                static fn (string $code): bool => isset($prices[$code]) && Currency::BASE->value !== $code,
            ));
            $items[(string) $ref] = [
                'name' => $item->name,
                'available' => $item->available,
                'price' => $item->unitPrice,
                'prices' => $prices,
                'overrides' => $overrides,
            ];
        }

        $currentRates = [];
        foreach ($context->options() as $currency) {
            if (Currency::BASE !== $currency) {
                $currentRates[$currency->value] = (float) $rates->rateFor($currency);
            }
        }

        $response = new JsonResponse([
            'ok' => true,
            'currency' => ['current' => $context->current()->value, 'rates' => [] === $currentRates ? new \stdClass() : $currentRates],
            'items' => [] === $items ? new \stdClass() : $items,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
