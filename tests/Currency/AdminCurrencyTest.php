<?php

namespace App\Tests\Currency;

use App\Entity\ExchangeRate;
use App\Entity\User;
use App\Enum\Currency;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeExchangeRateProvider;
use App\Tests\Support\ModerationHelpers;

/**
 * Écran « Devises » de l'administration : droits, mot de passe de confirmation, taux manuel, reprise de l'automatique, actualisation.
 */
final class AdminCurrencyTest extends AppTestCase
{
    use ModerationHelpers;

    private function provider(): FakeExchangeRateProvider
    {
        return static::getContainer()->get(FakeExchangeRateProvider::class);
    }

    private function latest(string $code): ?ExchangeRate
    {
        $this->em()->clear();

        return $this->em()->getRepository(ExchangeRate::class)->findOneBy(['currency' => Currency::from($code)], ['createdAt' => 'DESC']);
    }

    private function manager(): User
    {
        $admin = $this->adminWith(['currency.manage'], 'devises@example.com');
        $this->signInAdmin($admin);

        return $admin;
    }

    /** Fait passer du temps : une session d'administrateur inactive plus de 15 minutes se termine, on se reconnecte. */
    private function later(string $modify, User $admin): void
    {
        static::mockTime(new \DateTimeImmutable($modify));
        $this->newBrowser();
        $this->signInAdmin($admin);
    }

    // ------------------------------------------------------------------ Droits

    public function testThePageAndTheActionsNeedTheCurrencyRight(): void
    {
        $this->signInAdmin($this->adminWith([]));

        $this->client->request('GET', '/admin/devises');
        self::assertResponseStatusCodeSame(403);

        $this->api('POST', '/admin/api/devises/EUR/manuel', ['rate' => '5000', 'password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());
        $this->api('POST', '/admin/api/devises/EUR/automatique', ['password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());
        $this->api('POST', '/admin/api/devises/actualiser', []);
        self::assertSame(403, $this->httpStatus());
        self::assertNull($this->latest('EUR'));
    }

    public function testTheRightGivesAccessToThePageAndAToolLink(): void
    {
        $this->manager();

        $this->client->request('GET', '/admin/devises');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('section[data-currency="EUR"]');
        self::assertSelectorExists('section[data-currency="USD"]');
        self::assertSelectorNotExists('section[data-currency="MGA"]', 'L\'Ariary est la devise de base : pas de taux.');
        self::assertSelectorExists('[data-no-rate]', 'Aucun taux inventé : rien n\'est affiché tant que rien n\'est enregistré.');

        $this->client->request('GET', '/admin');
        self::assertSelectorExists('a[href="/admin/devises"]');
    }

    public function testSuperAdminAlsoHasAccess(): void
    {
        $root = $this->createSuperAdmin();
        $this->signInAdmin($root);

        $this->client->request('GET', '/admin/devises');
        self::assertResponseIsSuccessful();
    }

    // ------------------------------------------------------------------ Actualiser

    public function testRefreshNowFetchesTheRatesAndTheCurrentRateIsShown(): void
    {
        $this->manager();

        $r = $this->api('POST', '/admin/api/devises/actualiser', []);
        self::assertSame(200, $this->httpStatus());
        self::assertEqualsCanonicalizing(['EUR', 'USD'], $r['updated']);
        self::assertSame([], $r['problems']);
        self::assertSame('5000.000000', $this->latest('EUR')->getRate());

        $this->client->request('GET', '/admin/devises');
        self::assertSelectorTextContains('[data-currency="EUR"] [data-current-rate]', '5');
        self::assertSelectorNotExists('[data-no-rate]');
    }

    public function testRefreshReportsAProblemWithoutBreakingAndFailsCleanlyWhenTheServiceIsDown(): void
    {
        $admin = $this->manager();
        $this->api('POST', '/admin/api/devises/actualiser', []);

        $this->provider()->willReturn(['EUR' => '99999.000000', 'USD' => '4500.000000']);
        $this->later('+1 hour', $admin);
        $r = $this->api('POST', '/admin/api/devises/actualiser', []);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['EUR'], $r['problems'], 'Le taux aberrant est refusé et signalé.');
        self::assertSame('5000.000000', $this->latest('EUR')->getRate());

        $this->provider()->goDown();
        $r = $this->api('POST', '/admin/api/devises/actualiser', []);
        self::assertSame(503, $this->httpStatus());
        self::assertSame('rate_provider_down', $r['error']);
        self::assertStringNotContainsString('error.', $r['message']);
    }

    // ------------------------------------------------------------------ Taux manuel

    public function testManualRateNeedsThePasswordAndIsValidated(): void
    {
        $this->manager();

        $r = $this->api('POST', '/admin/api/devises/EUR/manuel', ['rate' => '5100', 'password' => 'faux']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('step_up_failed', $r['error']);
        self::assertNull($this->latest('EUR'));

        foreach (['', 'abc', '0', '-4'] as $bad) {
            $r = $this->api('POST', '/admin/api/devises/EUR/manuel', ['rate' => $bad, 'password' => self::PASSWORD]);
            self::assertSame(422, $this->httpStatus(), 'Saisie : '.$bad);
            self::assertSame('invalid_rate', $r['error']);
            self::assertStringNotContainsString('error.', $r['message']);
        }
        $r = $this->api('POST', '/admin/api/devises/EUR/manuel', ['rate' => '5100', 'days' => '400', 'password' => self::PASSWORD]);
        self::assertSame('invalid_days', $r['error']);
        $r = $this->api('POST', '/admin/api/devises/EUR/manuel', ['rate' => '5100', 'days' => 'abc', 'password' => self::PASSWORD]);
        self::assertSame('invalid_days', $r['error']);
        $r = $this->api('POST', '/admin/api/devises/MGA/manuel', ['rate' => '1', 'password' => self::PASSWORD]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_currency', $r['error']);
        $r = $this->api('POST', '/admin/api/devises/XYZ/manuel', ['rate' => '1', 'password' => self::PASSWORD]);
        self::assertSame('invalid_currency', $r['error']);
        self::assertNull($this->latest('EUR'));
    }

    public function testAManualRateIsSavedWithItsAuthorAndBlocksTheAutomaticUpdate(): void
    {
        $admin = $this->manager();
        $this->api('POST', '/admin/api/devises/actualiser', []);

        $r = $this->api('POST', '/admin/api/devises/eur/manuel', ['rate' => '5 250,5', 'days' => '', 'note' => 'Taux de la banque', 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus(), json_encode($r));
        self::assertSame('5250.500000', $r['rate']);
        $row = $this->latest('EUR');
        self::assertTrue($row->isManual());
        self::assertSame('Taux de la banque', $row->getNote());
        self::assertSame('devises@example.com', $row->getCreatedBy()?->getEmail());
        self::assertNull($row->getValidUntil());

        $this->later('+2 days', $admin);
        $r = $this->api('POST', '/admin/api/devises/actualiser', []);
        self::assertSame(['EUR'], $r['locked']);
        self::assertSame('5250.500000', $this->latest('EUR')->getRate());

        $this->client->request('GET', '/admin/devises');
        self::assertSelectorTextContains('[data-currency="EUR"]', 'Taux de la banque');
    }

    public function testResumingAutomaticModeNeedsThePasswordAndReplacesTheManualRate(): void
    {
        $this->manager();
        $this->api('POST', '/admin/api/devises/EUR/manuel', ['rate' => '5250', 'password' => self::PASSWORD]);

        $r = $this->api('POST', '/admin/api/devises/EUR/automatique', ['password' => 'faux']);
        self::assertSame(403, $this->httpStatus());
        self::assertTrue($this->latest('EUR')->isManual());

        $this->provider()->goDown();
        $r = $this->api('POST', '/admin/api/devises/EUR/automatique', ['password' => self::PASSWORD]);
        self::assertSame(503, $this->httpStatus());
        self::assertSame('rate_provider_down', $r['error']);
        self::assertTrue($this->latest('EUR')->isManual(), 'Le taux manuel reste en vigueur.');

        $this->provider()->willReturn(['EUR' => '5000.000000', 'USD' => '4500.000000']);
        $r = $this->api('POST', '/admin/api/devises/EUR/automatique', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertFalse($this->latest('EUR')->isManual());
        self::assertSame('5000.000000', $this->latest('EUR')->getRate());
    }

    public function testApiRefusesVisitorsAndCustomers(): void
    {
        $this->api('POST', '/admin/api/devises/actualiser', []);
        self::assertContains($this->httpStatus(), [401, 403]);

        $this->signIn($this->createUser('client@example.com'));
        $this->api('POST', '/admin/api/devises/EUR/manuel', ['rate' => '5000', 'password' => self::PASSWORD]);
        self::assertContains($this->httpStatus(), [401, 403]);
        self::assertNull($this->latest('EUR'));
    }
}
