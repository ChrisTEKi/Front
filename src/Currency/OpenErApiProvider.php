<?php

namespace App\Currency;

use App\Enum\Currency;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Taux récupérés auprès de open.er-api.com (service gratuit, sans clé, mis à jour une fois par jour).
 *
 * L'URL est configurable (variable `EXCHANGE_RATE_API_URL`, avec « {base} » remplacé par EUR puis USD). On demande les taux
 * AVEC l'euro, puis AVEC le dollar comme base : la réponse contient directement « Ariary pour 1 unité », sans diviser
 * un tout petit nombre (ce qui perdrait des chiffres significatifs).
 *
 * Réponse attendue :  {"result":"success","rates":{"MGA":5012.34, ...}}.   Toute autre forme est refusée.
 */
final class OpenErApiProvider implements ExchangeRateProvider
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $urlTemplate,
    ) {
    }

    public function fetch(): array
    {
        $rates = [];
        foreach (Currency::cases() as $currency) {
            if (Currency::BASE === $currency) {
                continue;
            }
            $rates[$currency->value] = $this->fetchOne($currency);
        }

        return $rates;
    }

    private function fetchOne(Currency $currency): string
    {
        try {
            $response = $this->http->request('GET', str_replace('{base}', $currency->value, $this->urlTemplate), ['timeout' => 8, 'max_duration' => 15]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            throw new ExchangeRateUnavailableException('Service de taux injoignable ('.$currency->value.') : '.$e->getMessage(), 0, $e);
        }

        $value = $data['rates'][Currency::BASE->value] ?? null;
        $usable = 'success' === ($data['result'] ?? 'success')
            && (\is_int($value) || \is_float($value))
            && is_finite((float) $value)
            && $value > 0;
        if (!$usable) {
            throw new ExchangeRateUnavailableException('Réponse inattendue du service de taux ('.$currency->value.').');
        }

        return number_format((float) $value, 6, '.', '');
    }
}
