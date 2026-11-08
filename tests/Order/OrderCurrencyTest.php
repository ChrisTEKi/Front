<?php

namespace App\Tests\Order;

use App\Currency\ExchangeRates;
use App\Entity\AuditLog;
use App\Entity\Order;
use App\Enum\Currency;
use App\Order\CatalogItem;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeExchangeRateProvider;
use App\Tests\Support\InMemoryCatalog;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Commandes en euro / dollar : prix du vendeur ou prix converti, taux figé, montants figés, règlement en Ariary.
 *
 * Taux du faux fournisseur : 1 € = 5 000 Ar, 1 $ = 4 500 Ar. Frais de livraison : 10 000 Ar (soit 2,00 €).
 */
final class OrderCurrencyTest extends AppTestCase
{
    private const ADDRESS = ['fullName' => 'Rakoto Jean', 'phone' => '', 'street' => 'Lot II A 124, Ankadifotsy', 'city' => 'Antananarivo', 'postalCode' => '101', 'courierNote' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        $catalog = static::getContainer()->get(InMemoryCatalog::class);
        $catalog->add(new CatalogItem('p-1', 'Casque audio', 120000));
        $catalog->add(new CatalogItem('p-2', 'Câble USB-C', 15000));
    }

    private function withRates(): void
    {
        static::getContainer()->get(ExchangeRates::class)->refresh();
        $this->em()->clear();
    }

    private function ready(): void
    {
        $this->client->getCookieJar()->set(new Cookie('gobuy_locale', 'fr'));
        $this->signIn($this->createUser('c@example.com', phone: '+261341234567', phoneVerified: true));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        return $extra + [
            'items' => [['ref' => 'p-1', 'quantity' => 1], ['ref' => 'p-2', 'quantity' => 2]],
            'address' => self::ADDRESS,
            'payment' => ['method' => 'cash_on_delivery'],
            'idempotencyKey' => bin2hex(random_bytes(8)),
        ];
    }

    private function order(): Order
    {
        $this->em()->clear();

        return $this->em()->getRepository(Order::class)->findOneBy([]);
    }

    private static function plain(string $text): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', $text);
    }

    // ------------------------------------------------------------------ Ariary : rien ne change

    public function testAnOrderWithoutCurrencyStaysInAriaryExactlyAsBefore(): void
    {
        $this->withRates();
        $this->ready();

        $this->api('POST', '/commande/valider', $this->body());
        self::assertSame(201, $this->httpStatus());

        $order = $this->order();
        self::assertSame(Currency::MGA, $order->getCurrency());
        self::assertFalse($order->isForeignCurrency());
        self::assertNull($order->getExchangeRate());
        self::assertSame(150000, $order->getSubtotal());
        self::assertSame(10000, $order->getDeliveryFee());
        self::assertSame(160000, $order->getTotal());
        self::assertSame(160000, $order->getChargedTotal(), 'En Ariary, le total annoncé est le total débité.');
        foreach ($order->getItems() as $item) {
            self::assertSame($item->getUnitPrice(), $item->getChargedUnitPrice());
        }
    }

    // ------------------------------------------------------------------ Prix converti

    public function testAnOrderInEuroIsConvertedAtTheCurrentRateAndTheRateIsFrozen(): void
    {
        $this->withRates();
        $this->ready();

        $r = $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR']));
        self::assertSame(201, $this->httpStatus(), json_encode($r));
        self::assertSame('EUR', $r['order']['currency']);
        self::assertSame(3200, $r['order']['totalCharged']);

        $order = $this->order();
        self::assertSame(Currency::EUR, $order->getCurrency());
        self::assertTrue($order->isForeignCurrency());
        self::assertSame('5000.000000', $order->getExchangeRate());
        // 120 000 Ar = 24,00 € ; 2 × 15 000 Ar = 2 × 3,00 € ; livraison 10 000 Ar = 2,00 €.
        self::assertSame(3000, $order->getChargedSubtotal());
        self::assertSame(200, $order->getChargedDeliveryFee());
        self::assertSame(3200, $order->getChargedTotal());
        // Ce qui est débité reste en Ariary, au prix du catalogue.
        self::assertSame(150000, $order->getSubtotal());
        self::assertSame(10000, $order->getDeliveryFee());
        self::assertSame(160000, $order->getTotal());

        $lines = [];
        foreach ($order->getItems() as $item) {
            $lines[$item->getProductRef()] = [$item->getChargedUnitPrice(), $item->getUnitPrice()];
        }
        self::assertSame(['p-1' => [2400, 120000], 'p-2' => [300, 15000]], $lines);
    }

    public function testADollarOrderAndALowerCaseCodeAreAccepted(): void
    {
        $this->withRates();
        $this->ready();

        $this->api('POST', '/commande/valider', $this->body(['currency' => 'usd', 'items' => [['ref' => 'p-2', 'quantity' => 1]]]));
        self::assertSame(201, $this->httpStatus());

        $order = $this->order();
        self::assertSame(Currency::USD, $order->getCurrency());
        // 15 000 Ar / 4 500 = 3,33 $ ; livraison 10 000 Ar = 2,22 $.
        self::assertSame(333, $order->getChargedSubtotal());
        self::assertSame(222, $order->getChargedDeliveryFee());
        self::assertSame(555, $order->getChargedTotal());
    }

    public function testLaterRateChangesNeverTouchAnExistingOrder(): void
    {
        $this->withRates();
        $this->ready();
        $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR']));
        self::assertSame(201, $this->httpStatus());

        static::getContainer()->get(FakeExchangeRateProvider::class)->willReturn(['EUR' => '5500.000000', 'USD' => '4500.000000']);
        static::getContainer()->get(ExchangeRates::class)->refresh();
        $this->em()->clear();

        $order = $this->order();
        self::assertSame('5000.000000', $order->getExchangeRate());
        self::assertSame(3200, $order->getChargedTotal());
        self::assertSame(160000, $order->getTotal());
    }

    // ------------------------------------------------------------------ Prix fixé par le vendeur

    public function testAPriceSetBySupplierForTheCurrencyIsUsedAndItsAriaryEquivalentIsDebited(): void
    {
        $this->withRates();
        static::getContainer()->get(InMemoryCatalog::class)->add(new CatalogItem('p-1', 'Casque audio', 120000, priceOverrides: ['EUR' => 2200]));
        $this->ready();

        $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR']));
        self::assertSame(201, $this->httpStatus());

        $order = $this->order();
        // Casque : 22,00 € (prix du vendeur) = 110 000 Ar au taux du jour ; câbles : convertis (2 × 3,00 €).
        self::assertSame(2800, $order->getChargedSubtotal());
        self::assertSame(3000, $order->getChargedTotal());
        self::assertSame(140000, $order->getSubtotal(), '110 000 Ar (équivalent du prix en euro) + 30 000 Ar.');
        self::assertSame(150000, $order->getTotal());

        $lines = [];
        foreach ($order->getItems() as $item) {
            $lines[$item->getProductRef()] = [$item->getChargedUnitPrice(), $item->getUnitPrice()];
        }
        self::assertSame(['p-1' => [2200, 110000], 'p-2' => [300, 15000]], $lines);
    }

    public function testASupplierPriceForAnotherCurrencyDoesNotApply(): void
    {
        $this->withRates();
        static::getContainer()->get(InMemoryCatalog::class)->add(new CatalogItem('p-1', 'Casque audio', 120000, priceOverrides: ['EUR' => 2200]));
        $this->ready();

        $this->api('POST', '/commande/valider', $this->body(['currency' => 'USD', 'items' => [['ref' => 'p-1', 'quantity' => 1]]]));
        self::assertSame(201, $this->httpStatus());

        $order = $this->order();
        self::assertSame(2667, $order->getChargedSubtotal(), '120 000 Ar / 4 500 = 26,67 $ : le prix en euro ne sert pas.');
        self::assertSame(120000, $order->getSubtotal());
    }

    public function testASupplierPriceIsIgnoredForAnOrderInAriary(): void
    {
        $this->withRates();
        static::getContainer()->get(InMemoryCatalog::class)->add(new CatalogItem('p-1', 'Casque audio', 120000, priceOverrides: ['EUR' => 100]));
        $this->ready();

        $this->api('POST', '/commande/valider', $this->body(['items' => [['ref' => 'p-1', 'quantity' => 1]]]));

        self::assertSame(120000, $this->order()->getSubtotal());
    }

    // ------------------------------------------------------------------ Refus

    public function testACurrencyWithoutRateIsRefusedAndNothingIsCreated(): void
    {
        $this->ready();

        $r = $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR']));

        self::assertSame(422, $this->httpStatus());
        self::assertSame('currency_unavailable', $r['error']);
        self::assertNotSame('', $r['message']);
        self::assertSame(0, $this->em()->getRepository(Order::class)->count([]));
    }

    public function testAnUnknownCurrencyIsRefused(): void
    {
        $this->withRates();
        $this->ready();

        foreach (['GBP', 'EURO', '12', '<script>'] as $code) {
            $r = $this->api('POST', '/commande/valider', $this->body(['currency' => $code]));
            self::assertSame(422, $this->httpStatus(), $code);
            self::assertSame('invalid_currency', $r['error'], $code);
        }
        self::assertSame(0, $this->em()->getRepository(Order::class)->count([]));
    }

    public function testTheClientCannotImposeAnAmount(): void
    {
        $this->withRates();
        $this->ready();

        // Les champs de montant envoyés par un navigateur truqué sont ignorés : seul le total relu par le serveur compte.
        $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR', 'total' => 1, 'totalCharged' => 1, 'exchangeRate' => '1', 'rate' => 1, 'prices' => ['p-1' => 1]]));
        self::assertSame(201, $this->httpStatus());

        $order = $this->order();
        self::assertSame(3200, $order->getChargedTotal());
        self::assertSame('5000.000000', $order->getExchangeRate());
    }

    // ------------------------------------------------------------------ Prix affiché = prix payé

    public function testTheOrderIsAcceptedWhenTheQuotedTotalIsStillRight(): void
    {
        $this->withRates();
        $this->ready();

        $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR', 'quotedTotal' => 3200]));

        self::assertSame(201, $this->httpStatus());
    }

    public function testTheOrderIsRefusedWhenTheRateChangedSinceThePriceWasShown(): void
    {
        $this->withRates();
        $this->ready();

        static::getContainer()->get(FakeExchangeRateProvider::class)->willReturn(['EUR' => '5500.000000', 'USD' => '4500.000000']);
        static::getContainer()->get(ExchangeRates::class)->refresh();
        $this->em()->clear();

        // Le client avait vu 32,00 € (taux 5 000) ; au taux de 5 500 le total est de 29,09 + 2,18 + ...
        $r = $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR', 'quotedTotal' => 3200]));

        self::assertSame(422, $this->httpStatus());
        self::assertSame('price_changed', $r['error']);
        self::assertSame(0, $this->em()->getRepository(Order::class)->count([]), 'Aucune commande au « mauvais » prix.');

        // Après actualisation, le nouveau total (celui que l'écran affiche alors) est accepté.
        $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR']));
        self::assertSame(201, $this->httpStatus());
        self::assertSame('5500.000000', $this->order()->getExchangeRate());
    }

    public function testTheQuotedTotalAlsoProtectsOrdersInAriary(): void
    {
        $this->ready();

        $this->api('POST', '/commande/valider', $this->body(['quotedTotal' => 150000]));
        self::assertSame(422, $this->httpStatus());

        $this->api('POST', '/commande/valider', $this->body(['quotedTotal' => 160000]));
        self::assertSame(201, $this->httpStatus());
    }

    public function testARefusedPriceDoesNotUseUpTheOrderQuota(): void
    {
        $this->withRates();
        $this->ready();

        for ($i = 0; $i < 12; ++$i) {
            $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR', 'quotedTotal' => 1]));
            self::assertSame(422, $this->httpStatus());
        }

        $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR', 'quotedTotal' => 3200]));
        self::assertSame(201, $this->httpStatus(), 'Les refus de prix ne comptent pas dans le plafond de commandes.');
    }

    // ------------------------------------------------------------------ Affichage et e-mail

    public function testTheTrackingPageShowsTheAmountsInTheOrderCurrencyAndTheArairySettlement(): void
    {
        $this->withRates();
        $this->ready();
        $r = $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR']));
        $number = $r['order']['orderNumber'];

        $this->client->request('GET', '/suivi/'.$number);
        self::assertResponseIsSuccessful();
        $html = self::plain((string) $this->client->getResponse()->getContent());

        self::assertStringContainsString('32,00 €', $html);
        self::assertStringContainsString('30,00 €', $html);
        self::assertStringContainsString('2,00 €', $html);
        self::assertStringContainsString('160 000 Ar', $html, 'Le montant réellement débité, en Ariary.');
        self::assertStringContainsString('1 € = 5 000 Ar', $html);
    }

    public function testTheTrackingPageOfAnAriaryOrderHasNoSettlementNote(): void
    {
        $this->withRates();
        $this->ready();
        $r = $this->api('POST', '/commande/valider', $this->body());

        $this->client->request('GET', '/suivi/'.$r['order']['orderNumber']);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-order-settlement]');
        self::assertStringContainsString('160 000 Ar', self::plain((string) $this->client->getResponse()->getContent()));
    }

    public function testTheConfirmationEmailShowsTheOrderCurrencyAndTheArairyAmount(): void
    {
        $this->withRates();
        $this->ready();
        $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR']));

        self::assertEmailCount(1);
        $mail = self::getMailerMessage(0);
        $html = self::plain((string) $mail->getHtmlBody());
        $text = self::plain((string) $mail->getTextBody());

        foreach ([$html, $text] as $body) {
            self::assertStringContainsString('32,00 €', $body);
            self::assertStringContainsString('160 000 Ar', $body);
            self::assertStringContainsString('1 € = 5 000 Ar', $body);
        }
    }

    public function testTheOrderAuditKeepsTheCurrencyAndTheAnnouncedTotal(): void
    {
        $this->withRates();
        $this->ready();
        $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR']));

        $logs = $this->em()->getRepository(AuditLog::class)->findBy(['action' => 'order.placed']);
        self::assertCount(1, $logs);
        $meta = $logs[0]->getMetadata();
        self::assertSame('EUR', $meta['currency']);
        self::assertSame(3200, $meta['totalCharged']);
        self::assertSame(160000, $meta['total']);
    }

    // ------------------------------------------------------------------ Vue administrateur

    public function testTheAdminOrderViewAndCsvShowTheAriaryAmountAndTheOrderCurrency(): void
    {
        $this->withRates();
        $this->ready();
        $r = $this->api('POST', '/commande/valider', $this->body(['currency' => 'EUR']));
        $number = $r['order']['orderNumber'];

        $this->newBrowser();
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));

        $crawler = $this->client->request('GET', '/admin?tab=exports');
        $token = (string) $crawler->filter('form[action="/admin/export/commandes"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/export/commandes', ['_token' => $token]);
        $csv = (string) $this->client->getInternalResponse()->getContent();

        self::assertStringContainsString('Devise de la commande', $csv);
        self::assertMatchesRegularExpression('/'.preg_quote($number, '/').'.*;160000;.*;EUR;3200;5000\.000000/', $csv, 'Ariary débités, puis devise, total annoncé (centimes) et taux figé.');
    }
}
