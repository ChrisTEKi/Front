<?php

namespace App\Currency;

use App\Enum\Currency;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Devise D'AFFICHAGE du visiteur (celle dans laquelle il voit les prix) : Ariary par défaut, euro ou dollar s'il l'a choisi.
 *
 * Le choix est mémorisé dans un cookie (`gobuy_currency`, 1 an) par la route /devise/{code} (voir CurrencyController). Il n'est
 * honoré QUE si la devise a un taux en vigueur : sans taux, on retombe sur l'Ariary (jamais de prix inventé).
 *
 * La devise de COMMANDE n'est pas lue ici : le navigateur l'envoie explicitement avec la commande et le serveur la revalide
 * (voir OrderService).
 */
final class CurrencyContext
{
    /** Nom du cookie qui mémorise le choix de devise. */
    public const COOKIE = 'gobuy_currency';

    public function __construct(
        private readonly RequestStack $requests,
        private readonly ExchangeRates $rates,
    ) {
    }

    /** Devise choisie par le visiteur, ou l'Ariary. */
    public function current(): Currency
    {
        $code = $this->requests->getCurrentRequest()?->cookies->get(self::COOKIE);
        $currency = \is_string($code) ? Currency::tryFrom($code) : null;

        return null !== $currency && $this->rates->isAvailable($currency) ? $currency : Currency::BASE;
    }

    /**
     * Devises proposables (l'Ariary toujours, puis celles qui ont un taux).
     *
     * @return list<Currency>
     */
    public function options(): array
    {
        return $this->rates->availableCurrencies();
    }

    /** Vrai si le visiteur a un vrai choix (au moins une devise en plus de l'Ariary). */
    public function hasChoice(): bool
    {
        return \count($this->options()) > 1;
    }
}
