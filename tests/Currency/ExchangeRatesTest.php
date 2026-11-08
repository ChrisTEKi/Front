<?php

namespace App\Tests\Currency;

use App\Admin\AdminActionException;
use App\Currency\ExchangeRates;
use App\Currency\ExchangeRateUnavailableException;
use App\Currency\OpenErApiProvider;
use App\Currency\RateRefreshReport;
use App\Entity\AuditLog;
use App\Entity\ExchangeRate;
use App\Entity\User;
use App\Enum\Currency;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeExchangeRateProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Taux de change : enregistrement, conversion, garde-fou, taux manuel, reprise du mode automatique, service en panne.
 */
final class ExchangeRatesTest extends AppTestCase
{
    private function rates(): ExchangeRates
    {
        return static::getContainer()->get(ExchangeRates::class);
    }

    private function provider(): FakeExchangeRateProvider
    {
        return static::getContainer()->get(FakeExchangeRateProvider::class);
    }

    private function admin(): User
    {
        $this->createAdminUser('finance@example.com', 'finance');
        $this->em()->clear();

        return $this->em()->getRepository(User::class)->findOneBy(['email' => 'finance@example.com']);
    }

    private function rows(?Currency $currency = null): int
    {
        $this->em()->clear();

        return $this->em()->getRepository(ExchangeRate::class)->count($currency ? ['currency' => $currency] : []);
    }

    private function refusal(callable $action): string
    {
        try {
            $action();
        } catch (AdminActionException $e) {
            return $e->reason;
        }
        self::fail('Une AdminActionException était attendue.');
    }

    // ------------------------------------------------------------------ Aucun taux inventé

    public function testWithoutAnyRateOnlyTheAriaryIsAvailableAndNothingIsInvented(): void
    {
        self::assertSame([Currency::MGA], $this->rates()->availableCurrencies());
        self::assertNull($this->rates()->rateFor(Currency::EUR));
        self::assertNull($this->rates()->fromMga(50000, Currency::EUR));
        self::assertNull($this->rates()->toMga(1000, Currency::USD));
        self::assertSame(50000, $this->rates()->fromMga(50000, Currency::MGA));
        self::assertSame(1.0, $this->rates()->rateFor(Currency::MGA));
        self::assertSame('1.000000', $this->rates()->rateTextFor(Currency::MGA));
    }

    // ------------------------------------------------------------------ Mise à jour automatique

    public function testRefreshStoresTheRatesAndConversionsUseThem(): void
    {
        $report = $this->rates()->refresh();

        self::assertEqualsCanonicalizing(['EUR', 'USD'], $report->updated());
        self::assertFalse($report->hasProblem());
        self::assertEqualsCanonicalizing([Currency::MGA, Currency::EUR, Currency::USD], $this->rates()->availableCurrencies());

        self::assertSame(1000, $this->rates()->fromMga(50000, Currency::EUR), '50 000 Ar à 5 000 Ar/€ = 10,00 € = 1 000 centimes.');
        self::assertSame(1000, $this->rates()->fromMga(45000, Currency::USD));
        self::assertSame(50000, $this->rates()->toMga(1000, Currency::EUR));
        self::assertSame(45000, $this->rates()->toMga(1000, Currency::USD));
        self::assertSame('5000.000000', $this->rates()->rateTextFor(Currency::EUR));
    }

    public function testAConvertedAmountIsRoundedAndNeverFallsToZero(): void
    {
        $this->rates()->refresh();

        self::assertSame(1, $this->rates()->fromMga(1, Currency::EUR), 'Un article payant ne devient jamais gratuit à cause de l\'arrondi.');
        self::assertSame(0, $this->rates()->fromMga(0, Currency::EUR));
        self::assertSame(50, $this->rates()->toMga(1, Currency::EUR), 'Un centime d\'euro = 0,01 € × 5 000 Ar = 50 Ar.');
        self::assertSame(1, $this->rates()->toMga(1, Currency::USD) > 0 ? 1 : 0, 'Jamais 0 Ar pour un montant positif.');
        // 12 345 Ar / 5 000 = 2,469 € → 247 centimes.
        self::assertSame(247, $this->rates()->fromMga(12345, Currency::EUR));
    }

    public function testAnUnchangedRateIsNotStoredAgainBeforeTheDailyHeartbeat(): void
    {
        $this->rates()->refresh();
        self::assertSame(1, $this->rows(Currency::EUR));

        $report = $this->rates()->refresh();
        self::assertSame(RateRefreshReport::UNCHANGED, $report->rows['EUR']['status']);
        self::assertSame(1, $this->rows(Currency::EUR));

        static::mockTime(new \DateTimeImmutable('+25 hours'));
        $report = $this->rates()->refresh();
        self::assertSame(RateRefreshReport::UPDATED, $report->rows['EUR']['status'], 'Une ligne par jour prouve que le service répond encore.');
        self::assertSame(2, $this->rows(Currency::EUR));
    }

    public function testANewRateWithinTheLimitBecomesCurrentAndTheHistoryIsKept(): void
    {
        $this->rates()->refresh();
        static::mockTime(new \DateTimeImmutable('+1 hour'));
        $this->provider()->willReturn(['EUR' => '5100.500000', 'USD' => '4500.000000']);
        $report = $this->rates()->refresh();

        self::assertSame(RateRefreshReport::UPDATED, $report->rows['EUR']['status']);
        self::assertSame(RateRefreshReport::UNCHANGED, $report->rows['USD']['status']);
        self::assertSame('5100.500000', $this->rates()->rateTextFor(Currency::EUR));
        self::assertSame(2, $this->rows(Currency::EUR), 'L\'ancien taux reste dans l\'historique.');
    }

    public function testAnAbnormalJumpIsRefusedAndReportedInTheAuditLog(): void
    {
        $this->rates()->refresh();
        static::mockTime(new \DateTimeImmutable('+1 hour'));
        $this->provider()->willReturn(['EUR' => '50000.000000', 'USD' => '4500.000000']);   // un zéro de trop

        $report = $this->rates()->refresh();

        self::assertSame(RateRefreshReport::SUSPICIOUS, $report->rows['EUR']['status']);
        self::assertTrue($report->hasProblem());
        self::assertSame('5000.000000', $this->rates()->rateTextFor(Currency::EUR), 'L\'ancien taux reste en vigueur.');
        self::assertSame(1, $this->rows(Currency::EUR));
        $this->em()->clear();
        $row = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'rate.rejected']);
        self::assertNotNull($row);
        self::assertSame('EUR', $row->getTargetId());
    }

    public function testTheProvidersMissingOrInvalidValuesAreIgnoredPerCurrency(): void
    {
        $this->provider()->willReturn(['EUR' => '5000.000000', 'USD' => 'n\'importe quoi']);
        $report = $this->rates()->refresh();

        self::assertSame(RateRefreshReport::UPDATED, $report->rows['EUR']['status']);
        self::assertSame(RateRefreshReport::MISSING, $report->rows['USD']['status']);
        self::assertTrue($report->hasProblem());
        self::assertFalse($this->rates()->isAvailable(Currency::USD));

        foreach (['0', '-5', '0.0', '', '1e3'] as $bad) {
            $this->provider()->willReturn(['EUR' => $bad, 'USD' => $bad]);
            self::assertSame(RateRefreshReport::MISSING, $this->rates()->refresh()->rows['EUR']['status'], 'Valeur refusée : '.$bad);
        }
        self::assertSame(1, $this->rows(), 'Aucune valeur refusée n\'a été enregistrée.');
    }

    public function testWhenTheServiceIsDownTheCurrentRatesStayAndTheFailureIsReported(): void
    {
        $this->rates()->refresh();
        $this->provider()->goDown();

        try {
            $this->rates()->refresh();
            self::fail('Le service est en panne : une exception est attendue.');
        } catch (ExchangeRateUnavailableException) {
            // attendu
        }
        self::assertSame('5000.000000', $this->rates()->rateTextFor(Currency::EUR));
        self::assertSame(2, $this->rows());
    }

    // ------------------------------------------------------------------ Taux manuel

    public function testAManualRateIsUsedAtOnceAndBlocksAutomaticUpdates(): void
    {
        $this->rates()->refresh();
        $row = $this->rates()->setManual($this->admin(), Currency::EUR, '5 200,5', null, 'Taux de la banque');

        self::assertTrue($row->isManual());
        self::assertSame('5200.500000', $this->rates()->rateTextFor(Currency::EUR));
        self::assertSame(961, $this->rates()->fromMga(50000, Currency::EUR), '50 000 × 100 / 5 200,5 = 961,45 → 961 centimes.');

        static::mockTime(new \DateTimeImmutable('+3 days'));
        $this->provider()->willReturn(['EUR' => '5100.000000', 'USD' => '4500.000000']);
        $report = $this->rates()->refresh();

        self::assertSame(RateRefreshReport::LOCKED, $report->rows['EUR']['status']);
        self::assertSame('5200.500000', $this->rates()->rateTextFor(Currency::EUR), 'Le taux manuel n\'est jamais écrasé.');
        self::assertSame(RateRefreshReport::UPDATED, $report->rows['USD']['status'], 'Le verrou ne concerne que l\'euro : le dollar continue (ligne quotidienne de contrôle).');
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.rate.manual']));
    }

    public function testATemporaryManualRateExpiresAndTheAutomaticModeTakesOverAgain(): void
    {
        $this->rates()->refresh();
        $this->rates()->setManual($this->admin(), Currency::USD, '4700', 2, null);

        static::mockTime(new \DateTimeImmutable('+1 day'));
        self::assertSame(RateRefreshReport::LOCKED, $this->rates()->refresh()->rows['USD']['status']);

        static::mockTime(new \DateTimeImmutable('+3 days'));
        $this->provider()->willReturn(['EUR' => '5000.000000', 'USD' => '4600.000000']);
        $report = $this->rates()->refresh();

        self::assertSame(RateRefreshReport::UPDATED, $report->rows['USD']['status'], 'La durée du taux manuel est écoulée.');
        self::assertSame('4600.000000', $this->rates()->rateTextFor(Currency::USD));
    }

    public function testResumingAutomaticModeFetchesTheRateNowWithoutTheJumpGuard(): void
    {
        $this->rates()->refresh();
        $admin = $this->admin();
        $this->rates()->setManual($admin, Currency::EUR, '5200', null, null);

        $this->provider()->willReturn(['EUR' => '9000.000000', 'USD' => '4500.000000']);   // +80 % : refusé par le robot, accepté sur demande
        $row = $this->rates()->resumeAutomatic($admin, Currency::EUR);

        self::assertFalse($row->isManual());
        self::assertSame('9000.000000', $this->rates()->rateTextFor(Currency::EUR));
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.rate.auto']));

        // Le verrou est levé : la mise à jour automatique reprend.
        static::mockTime(new \DateTimeImmutable('+1 hour'));
        $this->provider()->willReturn(['EUR' => '9100.000000', 'USD' => '4500.000000']);
        self::assertSame(RateRefreshReport::UPDATED, $this->rates()->refresh()->rows['EUR']['status']);
    }

    public function testResumingAutomaticModeFailsCleanlyWhenTheServiceIsDown(): void
    {
        $this->rates()->refresh();
        $admin = $this->admin();
        $this->rates()->setManual($admin, Currency::EUR, '5200', null, null);
        $this->provider()->goDown();

        self::assertSame('rate_provider_down', $this->refusal(fn () => $this->rates()->resumeAutomatic($admin, Currency::EUR)));
        self::assertSame('5200.000000', $this->rates()->rateTextFor(Currency::EUR), 'Le taux manuel reste en vigueur.');

        $this->provider()->willReturn(['USD' => '4500.000000']);
        self::assertSame('rate_missing', $this->refusal(fn () => $this->rates()->resumeAutomatic($admin, Currency::EUR)));
    }

    public function testManualRateValidation(): void
    {
        $admin = $this->admin();

        foreach (['', '0', '-3', 'abc', '5 000 000 000', '1,2,3', '12.1234567', '1e3'] as $bad) {
            self::assertSame('invalid_rate', $this->refusal(fn () => $this->rates()->setManual($admin, Currency::EUR, $bad, null, null)), 'Saisie refusée : '.$bad);
        }
        foreach ([0, -1, 366] as $days) {
            self::assertSame('invalid_days', $this->refusal(fn () => $this->rates()->setManual($admin, Currency::EUR, '5000', $days, null)), 'Durée refusée : '.$days);
        }
        self::assertSame('invalid_currency', $this->refusal(fn () => $this->rates()->setManual($admin, Currency::MGA, '5000', null, null)), 'L\'Ariary est la devise de base.');
        self::assertSame('invalid_currency', $this->refusal(fn () => $this->rates()->resumeAutomatic($admin, Currency::MGA)));
        self::assertSame(0, $this->rows());
    }

    // ------------------------------------------------------------------ Commande planifiée

    public function testTheRefreshCommandReportsSuccessAndFailure(): void
    {
        $command = (new Application(static::$kernel))->find('app:rates:refresh');
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('EUR', $tester->getDisplay());
        self::assertStringContainsString('5000.000000', $tester->getDisplay());
        self::assertSame(2, $this->rows());

        $this->provider()->goDown();
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('indisponibles', $tester->getDisplay());

        $this->provider()->willReturn(['EUR' => '5000.000000', 'USD' => '45000.000000']);   // +900 % sur le dollar
        static::mockTime(new \DateTimeImmutable('+1 hour'));
        self::assertSame(1, $tester->execute([]), 'Une valeur refusée est un échec à surveiller.');
        self::assertStringContainsString('REFUSÉ', $tester->getDisplay());
    }

    // ------------------------------------------------------------------ Fournisseur réel (HTTP simulé)

    public function testTheHttpProviderReadsTheArPerUnitFromTheServiceResponse(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse(json_encode(['result' => 'success', 'base_code' => str_contains($url, '/EUR') ? 'EUR' : 'USD', 'rates' => ['MGA' => str_contains($url, '/EUR') ? 5012.3456789 : 4511, 'EUR' => 1]]), ['response_headers' => ['content-type: application/json']]);
        });
        $provider = new OpenErApiProvider($client, 'https://rates.example/v6/latest/{base}');

        self::assertSame(['EUR' => '5012.345679', 'USD' => '4511.000000'], $provider->fetch());
        self::assertSame(['https://rates.example/v6/latest/EUR', 'https://rates.example/v6/latest/USD'], $urls);
    }

    public function testTheHttpProviderRefusesBrokenResponses(): void
    {
        $bodies = [
            'erreur' => new MockResponse('{"result":"error","error-type":"unsupported-code"}'),
            'sans MGA' => new MockResponse('{"result":"success","rates":{"USD":1}}'),
            'zéro' => new MockResponse('{"result":"success","rates":{"MGA":0}}'),
            'texte' => new MockResponse('{"result":"success","rates":{"MGA":"5000"}}'),
            'pas du JSON' => new MockResponse('<html>Bad gateway</html>'),
            'HTTP 500' => new MockResponse('{"result":"success","rates":{"MGA":5000}}', ['http_code' => 500]),
            'HTTP 429' => new MockResponse('', ['http_code' => 429]),
        ];
        foreach ($bodies as $label => $response) {
            $provider = new OpenErApiProvider(new MockHttpClient($response), 'https://rates.example/{base}');
            try {
                $provider->fetch();
                self::fail('Réponse acceptée à tort : '.$label);
            } catch (ExchangeRateUnavailableException) {
                self::assertTrue(true);
            }
        }
    }
}
