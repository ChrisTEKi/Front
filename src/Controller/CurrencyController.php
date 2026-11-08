<?php

namespace App\Controller;

use App\Currency\CurrencyContext;
use App\Currency\ExchangeRates;
use App\Enum\Currency;
use App\Http\SameSiteReferer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sélecteur de devise d'affichage : /devise/MGA, /devise/EUR, /devise/USD.
 *
 * Mémorise le choix dans un cookie d'un an puis revient à la page d'où l'on vient (si c'est une page de ce site). Une devise
 * sans taux en vigueur est ignorée (le visiteur garde son choix actuel). Ce choix ne change QUE l'affichage : il n'est pas
 * enregistré sur le compte, et le serveur recalcule toujours le prix de chaque commande.
 */
final class CurrencyController extends AbstractController
{
    #[Route('/devise/{code}', name: 'app_currency_switch', requirements: ['code' => '[A-Za-z]{3}'], methods: ['GET'])]
    public function switch(string $code, Request $request, ExchangeRates $rates): RedirectResponse
    {
        $response = new RedirectResponse(SameSiteReferer::pathFrom($request) ?? $this->generateUrl('app_home'));

        $currency = Currency::tryFrom(strtoupper($code));
        if (null !== $currency && $rates->isAvailable($currency)) {
            // Cookie lisible seulement par le serveur (HttpOnly), non envoyé depuis des sites tiers (Lax).
            $response->headers->setCookie(Cookie::create(CurrencyContext::COOKIE, $currency->value, strtotime('+1 year'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }
}
