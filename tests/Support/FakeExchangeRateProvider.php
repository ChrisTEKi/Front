<?php

namespace App\Tests\Support;

use App\Currency\ExchangeRateProvider;
use App\Currency\ExchangeRateUnavailableException;

/**
 * Faux service de taux de change : aucun appel réseau. Le test choisit les taux (Ariary pour 1 unité) ou simule une panne.
 */
final class FakeExchangeRateProvider implements ExchangeRateProvider
{
    /** @var array<string, string> */
    private array $rates = ['EUR' => '5000.000000', 'USD' => '4500.000000'];
    private bool $down = false;
    private int $calls = 0;

    /** @param array<string, string> $rates */
    public function willReturn(array $rates): void
    {
        $this->rates = $rates;
        $this->down = false;
    }

    public function goDown(): void
    {
        $this->down = true;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function fetch(): array
    {
        ++$this->calls;
        if ($this->down) {
            throw new ExchangeRateUnavailableException('Service de taux simulé en panne.');
        }

        return $this->rates;
    }
}
