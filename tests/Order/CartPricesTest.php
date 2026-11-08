<?php

namespace App\Tests\Order;

use App\Currency\ExchangeRates;
use App\Order\CatalogItem;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\InMemoryCatalog;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Route /panier/prix : prix actuels des produits du panier (le panier du navigateur est une copie d'affichage).
 */
final class CartPricesTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $catalog = static::getContainer()->get(InMemoryCatalog::class);
        $catalog->add(new CatalogItem('p-1', 'Casque audio', 120000, priceOverrides: ['EUR' => 2200]));
        $catalog->add(new CatalogItem('p-2', 'Câble USB-C', 15000));
        $catalog->add(new CatalogItem('p-off', 'Produit retiré', 5000, available: false));
    }

    /** @return array<string, mixed> */
    private function read(string $query, ?string $currency = null): array
    {
        $this->client->getCookieJar()->clear();
        if (null !== $currency) {
            $this->client->getCookieJar()->set(new Cookie('gobuy_currency', $currency));
        }
        $this->client->request('GET', '/panier/prix'.$query, server: ['HTTP_ACCEPT' => 'application/json']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testWithoutRatesOnlyTheAriaryPriceIsReturned(): void
    {
        $data = $this->read('?refs=p-1,p-2');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($data['ok']);
        self::assertSame('MGA', $data['currency']['current']);
        self::assertSame([], $data['currency']['rates']);
        self::assertSame(['MGA' => 120000], $data['items']['p-1']['prices']);
        self::assertSame([], $data['items']['p-1']['overrides'], 'Un prix en euro sans taux n\'est pas proposé.');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testWithRatesEveryPriceAndTheSupplierPricesAreReturned(): void
    {
        static::getContainer()->get(ExchangeRates::class)->refresh();
        $data = $this->read('?refs=p-1,p-2,p-off,inconnu', 'EUR');

        self::assertSame('EUR', $data['currency']['current']);
        self::assertEquals(['EUR' => 5000, 'USD' => 4500], $data['currency']['rates']);
        self::assertSame(['MGA' => 120000, 'EUR' => 2200, 'USD' => 2667], $data['items']['p-1']['prices']);
        self::assertSame(['EUR'], $data['items']['p-1']['overrides']);
        self::assertSame(['MGA' => 15000, 'EUR' => 300, 'USD' => 333], $data['items']['p-2']['prices']);
        self::assertTrue($data['items']['p-2']['available']);
        self::assertFalse($data['items']['p-off']['available'], 'Un produit retiré est signalé indisponible.');
        self::assertArrayNotHasKey('inconnu', $data['items']);
    }

    public function testMalformedAndOversizedListsAreHandled(): void
    {
        self::assertSame([], $this->read('')['items']);
        self::assertSame([], $this->read('?refs=,,,')['items']);
        self::assertSame([], $this->read('?refs[]=p-1')['items'], 'Un tableau à la place du texte est ignoré.');
        self::assertSame([], $this->read('?refs='.str_repeat('a', 200))['items']);

        $many = implode(',', array_map(static fn (int $i): string => 'x'.$i, range(1, 500)));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->read('?refs='.$many)['items']);
    }

    public function testTheRouteIsPublicAndReadOnly(): void
    {
        $this->read('?refs=p-1');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'Le panier est utilisable sans compte.');

        $this->client->request('POST', '/panier/prix', server: ['CONTENT_TYPE' => 'application/json']);
        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }
}
