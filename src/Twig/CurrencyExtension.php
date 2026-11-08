<?php

namespace App\Twig;

use App\Currency\CurrencyContext;
use App\Currency\ExchangeRates;
use App\Enum\Currency;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonctions Twig pour afficher les prix dans la devise choisie par le visiteur.
 *
 *   display_currency()          code de la devise d'affichage (« MGA », « EUR », « USD »)
 *   currency_options()          devises proposables (codes)
 *   money(minor, code?, lang?) montant mis en forme selon la langue (celle de la requête, ou `lang` pour un e-mail) : « 12 500 Ar », « 12,50 € », « $12.50 »
 *   price_in(product, code?)    prix d'un produit dans la devise (prix du vendeur pour cette devise, sinon converti)
 *   from_mga(mga, code?)        montant en Ariary converti dans la devise (pour les frais de livraison, etc.)
 *   product_prices(product)     prix du produit dans chaque devise proposable (pour le panier, qui tourne dans le navigateur)
 *   product_overrides(product)  devises pour lesquelles le vendeur a fixé un prix spécifique
 *   currency_rates()            Ariary pour 1 unité, par devise étrangère proposable (pour le panier)
 *   currency_symbol(code)       symbole de la devise : « Ar », « € », « $ »
 *   exchange_rate(text, lang?)  taux enregistré dans une commande (« 5012.340000 ») mis en forme : « 5 012,34 »
 *
 * Les montants sont des ENTIERS en unités mineures (centimes pour l'euro et le dollar, Ariary entiers pour l'Ariary).
 */
final class CurrencyExtension extends AbstractExtension
{
    /** Langue de l'interface → paramètres régionaux de mise en forme des montants. */
    private const FORMAT_LOCALES = ['fr' => 'fr_FR', 'en' => 'en_US', 'es' => 'es_ES'];

    public function __construct(
        private readonly CurrencyContext $context,
        private readonly ExchangeRates $rates,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_currency', fn (): string => $this->context->current()->value),
            new TwigFunction('currency_options', fn (): array => array_map(static fn (Currency $c): string => $c->value, $this->context->options())),
            new TwigFunction('money', $this->money(...)),
            new TwigFunction('price_in', $this->priceIn(...)),
            new TwigFunction('from_mga', $this->fromMga(...)),
            new TwigFunction('product_prices', $this->productPrices(...)),
            new TwigFunction('product_overrides', $this->productOverrides(...)),
            new TwigFunction('currency_rates', $this->currencyRates(...)),
            new TwigFunction('currency_symbol', static fn (string $code): string => (Currency::tryFrom($code) ?? Currency::BASE)->symbol()),
            new TwigFunction('exchange_rate', $this->exchangeRate(...)),
        ];
    }

    /** Montant (unités mineures) mis en forme ; l'Ariary garde la forme « 12 500 Ar » du site. */
    public function money(int $minor, ?string $code = null, ?string $lang = null): string
    {
        $currency = null === $code ? $this->context->current() : (Currency::tryFrom($code) ?? Currency::BASE);
        $locale = self::FORMAT_LOCALES[$lang ?? $this->requests->getCurrentRequest()?->getLocale() ?? 'fr'] ?? 'fr_FR';

        if (Currency::MGA === $currency) {
            $number = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $number->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0);

            return $number->format($minor).' Ar';
        }

        return (new \NumberFormatter($locale, \NumberFormatter::CURRENCY))->formatCurrency($minor / $currency->minorFactor(), $currency->value);
    }

    /** Taux figé d'une commande, lisible : « 5 012,34 » (jusqu'à 4 décimales, sans zéros inutiles). */
    public function exchangeRate(?string $rate, ?string $lang = null): string
    {
        $locale = self::FORMAT_LOCALES[$lang ?? $this->requests->getCurrentRequest()?->getLocale() ?? 'fr'] ?? 'fr_FR';
        $number = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $number->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 4);

        return (string) $number->format((float) $rate);
    }

    /**
     * @param array<string, mixed> $product tableau de ProductPresenter (clés « price » et « priceOverrides »)
     */
    public function priceIn(array $product, ?string $code = null): int
    {
        $currency = null === $code ? $this->context->current() : (Currency::tryFrom($code) ?? Currency::BASE);

        // Sans taux (cas anormal : la devise n'est pas proposée), on retombe sur le prix en Ariary.
        return $this->rates->priceIn((int) $product['price'], $this->overrides($product), $currency) ?? (int) $product['price'];
    }

    public function fromMga(int $mga, ?string $code = null): int
    {
        $currency = null === $code ? $this->context->current() : (Currency::tryFrom($code) ?? Currency::BASE);

        return $this->rates->fromMga($mga, $currency) ?? $mga;
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array<string, int>
     */
    public function productPrices(array $product): array
    {
        $prices = [];
        foreach ($this->context->options() as $currency) {
            $price = $this->rates->priceIn((int) $product['price'], $this->overrides($product), $currency);
            if (null !== $price) {
                $prices[$currency->value] = $price;
            }
        }

        return $prices;
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return list<string>
     */
    public function productOverrides(array $product): array
    {
        $available = array_map(static fn (Currency $c): string => $c->value, $this->context->options());

        return array_values(array_filter(array_keys($this->overrides($product)), static fn (string $code): bool => \in_array($code, $available, true)));
    }

    /** @return array<string, float>|\stdClass objet vide quand il n'y a aucun taux (pour un JSON « {} » et non « [] ») */
    public function currencyRates(): array|\stdClass
    {
        $rates = [];
        foreach ($this->context->options() as $currency) {
            if (Currency::BASE !== $currency) {
                $rates[$currency->value] = (float) $this->rates->rateFor($currency);
            }
        }

        return [] === $rates ? new \stdClass() : $rates;
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array<string, int>
     */
    private function overrides(array $product): array
    {
        $raw = $product['priceOverrides'] ?? [];

        return \is_array($raw) ? array_filter($raw, static fn (mixed $v): bool => \is_int($v) && $v > 0) : [];
    }
}
