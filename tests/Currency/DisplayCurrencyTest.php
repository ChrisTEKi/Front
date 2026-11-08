<?php

namespace App\Tests\Currency;

use App\Currency\ExchangeRates;
use App\Entity\Product;
use App\Tests\Support\AppTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Twig\Environment;

/**
 * Devise d'AFFICHAGE : sélecteur, cookie, prix du vendeur pour une devise ou prix converti, repli sur l'Ariary.
 */
final class DisplayCurrencyTest extends AppTestCase
{
    /** Taux du faux fournisseur : 1 € = 5 000 Ar et 1 $ = 4 500 Ar. */
    private function withRates(): void
    {
        static::getContainer()->get(ExchangeRates::class)->refresh();
        $this->em()->clear();
    }

    /** Produit de la boutique GoBuy (sans fournisseur) ; $overrides : prix propres à une devise, en unités mineures. */
    private function product(string $name, int $price, array $overrides = []): string
    {
        $product = new Product(null, $name, $price, new \DateTimeImmutable(), null, null, null, $overrides);
        $this->em()->persist($product);
        $this->em()->flush();
        $id = $product->getId()->toRfc4122();
        $this->em()->clear();

        return $id;
    }

    private function page(string $url, ?string $currencyCookie = null, string $lang = 'fr'): string
    {
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie('gobuy_locale', $lang));
        if (null !== $currencyCookie) {
            $this->client->getCookieJar()->set(new Cookie('gobuy_currency', $currencyCookie));
        }
        $this->client->request('GET', $url);

        return (string) $this->client->getResponse()->getContent();
    }

    /** Texte sans espaces insécables, pour comparer « 20,00 € » sans se soucier du type d'espace. */
    private static function plain(string $html): string
    {
        return str_replace(["\u{202F}", "\u{00A0}", '&nbsp;'], ' ', $html);
    }

    // ------------------------------------------------------------------ Choix de la devise

    public function testWithoutRatesOnlyTheAriaryIsOfferedAndTheSwitchIsIgnored(): void
    {
        $this->product('Casque', 100000);

        $this->client->request('GET', '/devise/EUR', server: ['HTTP_REFERER' => 'http://localhost/']);
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertNull($this->client->getResponse()->headers->getCookies()[0] ?? null, 'Aucun cookie pour une devise sans taux.');

        $html = self::plain($this->page('/', 'EUR'));
        self::assertStringContainsString('100 000 Ar', $html, 'Sans taux, le cookie « EUR » est ignoré : prix en Ariary.');
        self::assertStringNotContainsString('app_currency_switch', $html);
        self::assertStringNotContainsString('/devise/EUR', $html, 'Pas de sélecteur quand il n\'y a qu\'une devise.');
    }

    public function testTheSwitchSetsAHardenedCookieAndComesBackToTheSamePage(): void
    {
        $this->withRates();
        $this->client->request('GET', '/devise/usd', server: ['HTTP_REFERER' => 'http://localhost/produit/abc?x=1']);

        $response = $this->client->getResponse();
        self::assertTrue($response->isRedirect('/produit/abc?x=1'));
        $cookie = $response->headers->getCookies()[0];
        self::assertSame('gobuy_currency', $cookie->getName());
        self::assertSame('USD', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertGreaterThan(time() + 300 * 86400, $cookie->getExpiresTime(), 'Mémorisé environ un an.');
    }

    public function testTheSwitchNeverRedirectsOutsideTheSiteAndRefusesUnknownCodes(): void
    {
        $this->withRates();

        $this->client->request('GET', '/devise/EUR', server: ['HTTP_REFERER' => 'https://evil.example/phish']);
        self::assertSame('/', parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));

        $this->client->request('GET', '/devise/GBP');
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertCount(0, $this->client->getResponse()->headers->getCookies(), 'Une devise inconnue ne pose aucun cookie.');

        $this->client->request('GET', '/devise/EURO');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Le code doit faire 3 lettres.');
    }

    public function testTheSelectorAppearsOnceRatesExist(): void
    {
        $this->withRates();
        $html = $this->page('/');

        self::assertStringContainsString('/devise/EUR', $html);
        self::assertStringContainsString('/devise/USD', $html);
        self::assertStringContainsString('/devise/MGA', $html);
    }

    // ------------------------------------------------------------------ Prix affichés

    public function testPricesAreConvertedWhenTheSupplierSetNoPriceForTheCurrency(): void
    {
        $this->withRates();
        $this->product('Casque', 100000);

        $ariary = self::plain($this->page('/'));
        self::assertStringContainsString('100 000 Ar', $ariary);

        $euro = self::plain($this->page('/', 'EUR'));
        self::assertStringContainsString('20,00 €', $euro, '100 000 Ar à 5 000 Ar/€.');
        self::assertStringNotContainsString('data-product-price-label>100 000 Ar', $euro);

        $dollar = self::plain($this->page('/', 'USD'));
        self::assertMatchesRegularExpression('/\$22\.22|22,22 \$|22,22\s?US\$/u', $dollar, '100 000 Ar à 4 500 Ar/$ = 22,22 $.');
    }

    public function testASupplierPriceForTheCurrencyWinsOverTheConversion(): void
    {
        $this->withRates();
        $id = $this->product('Casque', 100000, ['EUR' => 1850]);

        $euro = self::plain($this->page('/', 'EUR'));
        self::assertStringContainsString('18,50 €', $euro, 'Prix choisi par le vendeur en euro.');
        self::assertStringNotContainsString('20,00 €', $euro);

        $page = self::plain($this->page('/produit/'.$id, 'EUR'));
        self::assertStringContainsString('18,50 €', $page);

        $dollar = self::plain($this->page('/produit/'.$id, 'USD'));
        self::assertStringNotContainsString('18,50', $dollar, 'Le prix en euro ne sert pas au dollar : celui-ci est converti.');
    }

    public function testTheCartGetsEveryPriceAndTheListOfCurrenciesPricedBySupplier(): void
    {
        $this->withRates();
        $id = $this->product('Casque', 100000, ['EUR' => 1850]);

        $html = $this->page('/produit/'.$id);
        self::assertSame(1, preg_match('/data-product-prices="([^"]*)"/', $html, $prices), 'Attribut data-product-prices absent.');
        self::assertSame(['MGA' => 100000, 'EUR' => 1850, 'USD' => 2222], json_decode(html_entity_decode($prices[1]), true));
        self::assertSame(1, preg_match('/data-product-overrides="([^"]*)"/', $html, $overrides));
        self::assertSame(['EUR'], json_decode(html_entity_decode($overrides[1]), true));
        self::assertSame(1, preg_match('/<script[^>]*id="gb-currency"[^>]*>(.*?)<\/script>/s', $html, $context));
        self::assertEquals(['current' => 'MGA', 'rates' => ['EUR' => 5000, 'USD' => 4500]], json_decode($context[1], true));
    }

    public function testDeliveryFeeFollowsTheDisplayCurrency(): void
    {
        $this->withRates();
        $this->product('Casque', 100000);

        self::assertStringContainsString('10 000 Ar', self::plain($this->page('/')));
        self::assertStringContainsString('2,00 €', self::plain($this->page('/', 'EUR')), '10 000 Ar à 5 000 Ar/€.');
    }

    // ------------------------------------------------------------------ Mise en forme

    public function testMoneyIsFormattedForTheLanguageOfTheReader(): void
    {
        $this->withRates();
        $twig = static::getContainer()->get(Environment::class);
        $render = fn (string $expr): string => self::plain($twig->createTemplate('{{ '.$expr.' }}')->render([]));

        self::assertSame('12 500 Ar', $render("money(12500, 'MGA')"));
        self::assertSame('12,50 €', $render("money(1250, 'EUR', 'fr')"));
        self::assertSame('€12.50', $render("money(1250, 'EUR', 'en')"));
        self::assertSame('$12.50', $render("money(1250, 'USD', 'en')"));
        self::assertSame('12 500 Ar', $render("money(12500, 'XXX')"), 'Devise inconnue : Ariary.');
    }
}
