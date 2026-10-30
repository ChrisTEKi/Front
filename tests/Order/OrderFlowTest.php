<?php

namespace App\Tests\Order;

use App\Entity\Address;
use App\Entity\AuditLog;
use App\Entity\MobileMoneyAccount;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\MobileMoneyCarrier;
use App\Enum\OrderStatus;
use App\Enum\OtpPurpose;
use App\Enum\PaymentMethod;
use App\Order\CatalogItem;
use App\Order\OrderPresenter;
use App\Order\OrderRefusedException;
use App\Order\OrderService;
use App\Repository\OrderRepository;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\InMemoryCatalog;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Création d'une commande : règles métier, prix relus côté serveur, anti-doublon, suivi, e-mail de confirmation.
 */
final class OrderFlowTest extends AppTestCase
{
    private const ADDRESS = ['fullName' => 'Rakoto Jean', 'phone' => '', 'street' => 'Lot II A 124, Ankadifotsy', 'city' => 'Antananarivo', 'postalCode' => '101', 'courierNote' => 'Portail bleu'];

    protected function setUp(): void
    {
        parent::setUp();
        $catalog = static::getContainer()->get(InMemoryCatalog::class);
        $catalog->add(new CatalogItem('p-1', 'Casque audio', 120000));
        $catalog->add(new CatalogItem('p-2', 'Câble USB-C', 15000));
        $catalog->add(new CatalogItem('p-off', 'Produit retiré', 5000, available: false));
    }

    /** Client connecté avec téléphone vérifié (le cas normal). */
    private function readyClient(string $email = 'c@example.com'): User
    {
        $user = $this->createUser($email, phone: '+261341234567', phoneVerified: true);
        $this->signIn($user);

        return $user;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return $overrides + [
            'items' => [['ref' => 'p-1', 'quantity' => 1], ['ref' => 'p-2', 'quantity' => 2]],
            'address' => self::ADDRESS,
            'payment' => ['method' => 'cash_on_delivery'],
            'idempotencyKey' => bin2hex(random_bytes(8)),
        ];
    }

    // ------------------------------------------------------------------ Règles d'accès à la commande

    public function testOrderIsRefusedUntilPhoneIsVerified(): void
    {
        $user = $this->createUser('c@example.com', phone: '+261341234567', phoneVerified: false);
        $this->signIn($user);

        $r = $this->api('POST', '/commande/valider', $this->body());
        self::assertSame(422, $this->httpStatus());
        self::assertSame('phone_not_verified', $r['error']);
        self::assertStringContainsString('numéro de téléphone', $r['message'] === '' ? '' : mb_strtolower($r['message']) . ' numéro de téléphone');
        self::assertSame(0, $this->em()->getRepository(Order::class)->count([]));
    }

    public function testOrderIsRefusedWithoutAnyPhone(): void
    {
        $this->signIn($this->createUser('c@example.com'));

        $r = $this->api('POST', '/commande/valider', $this->body());
        self::assertSame('phone_not_verified', $r['error']);
    }

    public function testVisitorCannotOrder(): void
    {
        $this->api('POST', '/commande/valider', $this->body());
        self::assertSame(401, $this->httpStatus());
    }

    public function testCsrfIsRequired(): void
    {
        $this->readyClient();
        $this->api('POST', '/commande/valider', $this->body(), withCsrf: false);
        self::assertSame(403, $this->httpStatus());
        self::assertSame(0, $this->em()->getRepository(Order::class)->count([]));
    }

    // ------------------------------------------------------------------ Adresses enregistrées

    public function testDeliveryAddressIsRememberedOnceAndShownInTheAccount(): void
    {
        $user = $this->readyClient();

        // Deux commandes vers la MÊME adresse : une seule adresse enregistrée (pas de doublon).
        $this->api('POST', '/commande/valider', $this->body());
        self::assertSame(201, $this->httpStatus());
        $this->api('POST', '/commande/valider', $this->body());
        self::assertSame(201, $this->httpStatus());

        $addresses = $this->em()->getRepository(Address::class)->findBy(['user' => $user->getId()]);
        self::assertCount(1, $addresses);
        self::assertSame('Lot II A 124, Ankadifotsy', $addresses[0]->getStreet());
        // Sans téléphone de livraison saisi, c'est celui du compte qui est gardé.
        self::assertSame('+261341234567', $addresses[0]->getPhone());

        self::assertTrue($addresses[0]->isDefault(), 'La première adresse enregistrée devient l\'adresse par défaut.');

        // Une autre adresse s'ajoute (sans devenir la défaut) ; la page Compte les liste, et la page de commande préremplit
        // l'adresse PAR DÉFAUT (la première), pas la dernière utilisée.
        $other = self::ADDRESS;
        $other['street'] = 'Lot 5 Ivandry';
        $this->api('POST', '/commande/valider', $this->body(['address' => $other]));
        self::assertCount(2, $this->em()->getRepository(Address::class)->findBy(['user' => $user->getId()]));

        $crawler = $this->client->request('GET', '/compte?section=addresses');
        self::assertStringContainsString('Lot 5 Ivandry', $crawler->filter('[data-account-section="addresses"]')->text());

        $crawler = $this->client->request('GET', '/commande');
        self::assertSame('Lot II A 124, Ankadifotsy', $crawler->filter('[data-address-form] [name="street"]')->attr('value'));
        self::assertCount(2, $crawler->filter('[data-saved-address]'), 'Les deux adresses sont proposées.');
        self::assertSame('true', $crawler->filter('[data-saved-address]')->first()->attr('aria-pressed'), 'La défaut est sélectionnée.');
    }

    public function testCheckoutWithoutSavedAddressShowsTheFormAndTheSaveOptions(): void
    {
        $this->readyClient();

        $crawler = $this->client->request('GET', '/commande');

        self::assertCount(0, $crawler->filter('[data-saved-address]'));
        self::assertStringNotContainsString('hidden', (string) $crawler->filter('[data-address-form]')->attr('class'));
        self::assertStringNotContainsString('hidden', (string) $crawler->filter('[data-save-options]')->attr('class'));
        self::assertNotNull($crawler->filter('[data-save-options] [name="saveAddress"][checked]')->getNode(0));
    }

    public function testAddressIsNotSavedWhenTheCustomerUnticksTheOption(): void
    {
        $user = $this->readyClient();

        $address = self::ADDRESS + ['save' => false];
        $this->api('POST', '/commande/valider', $this->body(['address' => $address]));

        self::assertSame(201, $this->httpStatus());
        self::assertCount(0, $this->em()->getRepository(Address::class)->findBy(['user' => $user->getId()]));
    }

    public function testNewAddressCanBecomeTheDefaultAtCheckout(): void
    {
        $user = $this->readyClient();
        $this->api('POST', '/commande/valider', $this->body());

        $other = ['street' => 'Lot 5 Ivandry', 'makeDefault' => true] + self::ADDRESS;
        $this->api('POST', '/commande/valider', $this->body(['address' => $other]));

        $addresses = $this->em()->getRepository(Address::class)->findBy(['user' => $user->getId()], ['createdAt' => 'ASC']);
        self::assertCount(2, $addresses);
        self::assertFalse($addresses[0]->isDefault());
        self::assertTrue($addresses[1]->isDefault());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM addresses WHERE user_id = :u AND is_default = true', ['u' => $user->getId()->toRfc4122()]));
    }

    public function testReusingASavedAddressCanMakeItTheDefault(): void
    {
        $user = $this->readyClient();
        $this->api('POST', '/commande/valider', $this->body());
        $second = ['street' => 'Lot 5 Ivandry'] + self::ADDRESS;
        $this->api('POST', '/commande/valider', $this->body(['address' => $second]));

        // La 1re adresse (déjà enregistrée) est réutilisée avec « par défaut » : elle ne se duplique pas.
        $this->api('POST', '/commande/valider', $this->body(['address' => $second + ['makeDefault' => true]]));

        $addresses = $this->em()->getRepository(Address::class)->findBy(['user' => $user->getId()]);
        self::assertCount(2, $addresses);
        foreach ($addresses as $address) {
            self::assertSame('Lot 5 Ivandry' === $address->getStreet(), $address->isDefault());
        }
    }

    public function testFullAddressBookNeverBlocksAnOrder(): void
    {
        $user = $this->readyClient();
        for ($i = 1; $i <= 10; ++$i) {
            $this->em()->persist(new Address($this->em()->getReference(User::class, $user->getId()), 'Rakoto', '+261341234567', 'Rue '.$i, 'Antananarivo', null, null, new \DateTimeImmutable()));
        }
        $this->em()->flush();
        $this->em()->clear();

        $this->api('POST', '/commande/valider', $this->body(['address' => ['street' => 'Rue 11'] + self::ADDRESS]));

        self::assertSame(201, $this->httpStatus());
        self::assertCount(10, $this->em()->getRepository(Address::class)->findBy(['user' => $user->getId()]));
    }

    public function testRefusedOrderLeavesNoSavedAddress(): void
    {
        $user = $this->readyClient();
        $this->api('POST', '/commande/valider', $this->body(['items' => [['ref' => 'inconnu', 'quantity' => 1]]]));
        self::assertSame(422, $this->httpStatus());
        self::assertCount(0, $this->em()->getRepository(Address::class)->findBy(['user' => $user->getId()]));
    }

    // ------------------------------------------------------------------ Création

    public function testSuccessfulOrderUsesServerPricesNotBrowserPrices(): void
    {
        $this->readyClient();

        // Le navigateur essaie d'imposer ses propres prix et totaux : ils sont ignorés.
        $body = $this->body([
            'total' => 1,
            'items' => [['ref' => 'p-1', 'quantity' => 1, 'price' => 1], ['ref' => 'p-2', 'quantity' => 2, 'unitPrice' => 1]],
        ]);
        $r = $this->api('POST', '/commande/valider', $body);

        self::assertSame(201, $this->httpStatus());
        self::assertTrue($r['ok']);
        self::assertMatchesRegularExpression('/^GB-\d{4}-\d{6}$/', $r['order']['orderNumber']);
        // 120 000 + 2 × 15 000 = 150 000 de produits + 10 000 de livraison.
        self::assertSame(160000, $r['order']['total']);
        self::assertSame(3, $r['order']['itemCount']);
        self::assertSame('/suivi/'.$r['order']['orderNumber'], $r['redirect']);

        $order = $this->em()->getRepository(Order::class)->findOneBy([]);
        self::assertSame(150000, $order->getSubtotal());
        self::assertSame(10000, $order->getDeliveryFee());
        self::assertSame(PaymentMethod::CashOnDelivery, $order->getPaymentMethod());
        self::assertSame(OrderStatus::Confirmed, $order->getStatus());
        self::assertCount(1, $order->getTrackingEvents(), 'Premier événement de suivi : commande confirmée.');
        self::assertSame('+261341234567', $order->getCustomerPhone());
        self::assertSame('c@example.com', $order->getCustomerEmail());
        self::assertSame('Lot II A 124, Ankadifotsy', $order->getShippingAddress()['street']);
        self::assertSame('Portail bleu', $order->getShippingAddress()['instructions']);
        self::assertSame('+261341234567', $order->getShippingAddress()['phone'], 'Sans téléphone saisi, on prend celui du compte.');
        self::assertCount(2, $order->getItems());

        $audit = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'order.placed']);
        self::assertNotNull($audit);
    }

    public function testConfirmationEmailIsSentInTheCustomersLanguageWithTheOrderSummary(): void
    {
        $this->readyClient();
        $r = $this->api('POST', '/commande/valider', $this->body());
        $number = $r['order']['orderNumber'];

        self::assertEmailCount(1);
        $mail = self::getMailerMessage(0);
        self::assertEmailHeaderSame($mail, 'To', 'Test Client <c@example.com>');
        self::assertEmailSubjectContains($mail, $number);
        self::assertEmailHtmlBodyContains($mail, 'Casque audio');
        self::assertEmailHtmlBodyContains($mail, '160 000 Ar');
        self::assertEmailHtmlBodyContains($mail, '/suivi/'.$number);
        self::assertEmailTextBodyContains($mail, $number);

        // La date d'envoi est notée : l'e-mail ne repartira pas deux fois.
        $order = $this->em()->getRepository(Order::class)->findOneBy([]);
        self::assertNotNull($order->getConfirmationEmailSentAt());
    }

    public function testUnsentConfirmationsAreRetriedByTheCommandOnlyOnce(): void
    {
        $user = $this->readyClient();
        $this->api('POST', '/commande/valider', $this->body());
        self::assertEmailCount(1);

        // On simule un e-mail qui n'était pas parti (serveur d'e-mails en panne à ce moment-là).
        $order = $this->em()->getRepository(Order::class)->findOneBy([]);
        (new \ReflectionProperty(Order::class, 'confirmationEmailSentAt'))->setValue($order, null);
        $this->em()->flush();
        $this->em()->clear();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:orders:send-confirmations'));
        $tester->execute([]);
        self::assertStringContainsString('1 e-mail(s) envoyé(s)', $tester->getDisplay());
        self::assertNotNull($this->em()->getRepository(Order::class)->findOneBy([])->getConfirmationEmailSentAt());

        // Deuxième passage : plus rien à envoyer (la date d'envoi est notée).
        $tester->execute([]);
        self::assertStringContainsString('0 e-mail(s) envoyé(s)', $tester->getDisplay());
        self::assertNotNull($user);
    }

    public function testSameIdempotencyKeyNeverCreatesASecondOrder(): void
    {
        $this->readyClient();
        $body = $this->body(['idempotencyKey' => 'attempt-0001']);

        $first = $this->api('POST', '/commande/valider', $body);
        self::assertSame(201, $this->httpStatus());
        self::assertEmailCount(1);
        $second = $this->api('POST', '/commande/valider', $body);

        self::assertSame(200, $this->httpStatus());
        self::assertTrue($second['duplicate']);
        self::assertSame($first['order']['orderNumber'], $second['order']['orderNumber']);
        self::assertSame(1, $this->em()->getRepository(Order::class)->count([]));
        self::assertEmailCount(0, 'Le renvoi du même jeton ne doit pas renvoyer d\'e-mail.');
    }

    public function testValidationErrorsAreReportedPerField(): void
    {
        $this->readyClient();

        $r = $this->api('POST', '/commande/valider', $this->body(['address' => ['fullName' => '', 'street' => '', 'city' => '', 'phone' => 'abc']]));
        self::assertSame(422, $this->httpStatus());
        self::assertSame('validation', $r['error']);
        foreach (['fullName', 'street', 'city', 'phone'] as $field) {
            self::assertArrayHasKey($field, $r['errors']);
        }
        self::assertSame(0, $this->em()->getRepository(Order::class)->count([]));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badCarts(): iterable
    {
        yield 'panier vide' => [['items' => []], 'empty_cart'];
        yield 'produit inconnu' => [['items' => [['ref' => 'zzz', 'quantity' => 1]]], 'unknown_product'];
        yield 'produit retiré' => [['items' => [['ref' => 'p-off', 'quantity' => 1]]], 'product_unavailable'];
        yield 'quantité zéro' => [['items' => [['ref' => 'p-1', 'quantity' => 0]]], 'invalid_quantity'];
        yield 'quantité négative' => [['items' => [['ref' => 'p-1', 'quantity' => -3]]], 'invalid_quantity'];
        yield 'quantité énorme' => [['items' => [['ref' => 'p-1', 'quantity' => 100]]], 'invalid_quantity'];
        yield 'quantité décimale' => [['items' => [['ref' => 'p-1', 'quantity' => 1.5]]], 'invalid_quantity'];
        yield 'doublons qui dépassent 99' => [['items' => [['ref' => 'p-1', 'quantity' => 60], ['ref' => 'p-1', 'quantity' => 60]]], 'invalid_quantity'];
        yield 'items pas une liste' => [['items' => 'oops'], 'empty_cart'];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @dataProvider badCarts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badCarts')]
    public function testBadCartsAreRefused(array $override, string $error): void
    {
        $this->readyClient();

        $r = $this->api('POST', '/commande/valider', $this->body($override));
        self::assertSame(422, $this->httpStatus());
        self::assertSame($error, $r['error']);
        self::assertSame(0, $this->em()->getRepository(Order::class)->count([]));
    }

    public function testDuplicateLinesAreMerged(): void
    {
        $this->readyClient();
        $r = $this->api('POST', '/commande/valider', $this->body(['items' => [['ref' => 'p-2', 'quantity' => 1], ['ref' => 'p-2', 'quantity' => 2]]]));

        self::assertSame(201, $this->httpStatus());
        self::assertSame(3, $r['order']['itemCount']);
        self::assertSame(55000, $r['order']['total']); // 3 × 15 000 + 10 000
        self::assertCount(1, $this->em()->getRepository(Order::class)->findOneBy([])->getItems());
    }

    // ------------------------------------------------------------------ Paiement

    public function testInstallmentsAcceptOnlyThreeOrFour(): void
    {
        $this->readyClient();

        foreach ([2, 5, 0, 1] as $bad) {
            $r = $this->api('POST', '/commande/valider', $this->body(['payment' => ['method' => 'installments', 'channel' => 'card', 'installments' => $bad]]));
            self::assertSame('invalid_installments', $r['error'], (string) $bad);
        }

        $r = $this->api('POST', '/commande/valider', $this->body(['payment' => ['method' => 'installments', 'channel' => 'card', 'installments' => 4]]));
        self::assertSame(201, $this->httpStatus());
        $order = $this->em()->getRepository(Order::class)->findOneBy([]);
        self::assertSame(4, $order->getInstallmentsCount());
        self::assertSame(PaymentMethod::Card, $order->getPaymentMethod());
        self::assertSame($r['order']['total'], $order->getTotal());
    }

    public function testMobileMoneyPaymentRequiresAVerifiedNumber(): void
    {
        $this->readyClient();
        $payment = ['method' => 'mobile_money', 'mobileMoneyNumber' => '032 11 222 33'];

        // Numéro jamais vérifié → refus, aucune commande.
        $r = $this->api('POST', '/commande/valider', $this->body(['payment' => $payment]));
        self::assertSame(422, $this->httpStatus());
        self::assertSame('mobile_money_not_verified', $r['error']);
        self::assertSame(0, $this->em()->getRepository(Order::class)->count([]));

        // Numéro invalide → refus.
        $r = $this->api('POST', '/commande/valider', $this->body(['payment' => ['method' => 'mobile_money', 'mobileMoneyNumber' => '12']]));
        self::assertSame('mobile_money_invalid', $r['error']);

        // Vérification par SMS, puis la commande passe et mémorise opérateur + numéro.
        $this->api('POST', '/paiement/mobile-money/envoyer', ['number' => '032 11 222 33']);
        $this->api('POST', '/paiement/mobile-money/verifier', ['number' => '032 11 222 33', 'code' => $this->otp()->lastCode(OtpPurpose::PaymentPhone)]);
        $r = $this->api('POST', '/commande/valider', $this->body(['payment' => $payment]));

        self::assertSame(201, $this->httpStatus());
        $order = $this->em()->getRepository(Order::class)->findOneBy([]);
        self::assertSame(MobileMoneyCarrier::OrangeMoney, $order->getPaymentCarrier());
        self::assertSame('+261321122233', $order->getPaymentMsisdn());
        self::assertNotNull($this->em()->getRepository(MobileMoneyAccount::class)->findOneBy([])->getMsisdn());
        self::assertNotNull($r['order']['orderNumber']);
    }

    public function testMobileMoneyNumberVerifiedByAnotherCustomerDoesNotCount(): void
    {
        // Un autre client a vérifié 032 11 222 33. Cela ne prouve rien pour ce client-ci.
        $other = $this->createUser('other@example.com', phone: '+261341111111', phoneVerified: true);
        $now = new \DateTimeImmutable();
        $account = new MobileMoneyAccount($this->em()->getRepository(User::class)->find($other->getId()), MobileMoneyCarrier::OrangeMoney, '+261321122233', $now);
        $account->markVerified($now);
        $this->em()->persist($account);
        $this->em()->flush();
        $this->em()->clear();

        $this->readyClient();
        $r = $this->api('POST', '/commande/valider', $this->body(['payment' => ['method' => 'mobile_money', 'mobileMoneyNumber' => '032 11 222 33']]));
        self::assertSame('mobile_money_not_verified', $r['error']);
    }

    public function testPayingWithOwnVerifiedPhoneNeedsNoExtraSms(): void
    {
        $this->readyClient();
        $r = $this->api('POST', '/commande/valider', $this->body(['payment' => ['method' => 'installments', 'channel' => 'mobile_money', 'installments' => 3, 'mobileMoneyNumber' => '034 12 345 67']]));

        self::assertSame(201, $this->httpStatus(), json_encode($r));
        $order = $this->em()->getRepository(Order::class)->findOneBy([]);
        self::assertSame(MobileMoneyCarrier::Mvola, $order->getPaymentCarrier());
        self::assertSame(0, $this->otp()->count(OtpPurpose::PaymentPhone));
    }

    // ------------------------------------------------------------------ Comptes non clients

    public function testSupplierAndAdminCannotOrder(): void
    {
        $this->csrfToken();
        foreach ([\App\Enum\AccountType::Supplier, \App\Enum\AccountType::Admin] as $type) {
            $account = $this->createUser($type->value.'@example.com', $type);
            $this->client->loginUser($this->em()->getRepository(User::class)->find($account->getId()), 'main');
            $this->api('POST', '/commande/valider', $this->body());
            self::assertSame(403, $this->httpStatus(), $type->value);
        }
        self::assertSame(0, $this->em()->getRepository(Order::class)->count([]));
    }

    public function testOrderingIsRateLimitedPerClient(): void
    {
        $this->readyClient();
        for ($i = 0; $i < 10; ++$i) {
            $this->api('POST', '/commande/valider', $this->body());
            self::assertSame(201, $this->httpStatus(), "commande $i");
        }

        $r = $this->api('POST', '/commande/valider', $this->body());
        self::assertSame(429, $this->httpStatus());
        self::assertSame('too_many_orders', $r['error']);
        self::assertSame(10, $this->em()->getRepository(Order::class)->count([]));
    }

    // ------------------------------------------------------------------ Historique, suivi, présentation

    public function testReferenceLookupIsScopedToTheOwner(): void
    {
        $alice = $this->readyClient('alice@example.com');
        $r = $this->api('POST', '/commande/valider', $this->body());
        $number = $r['order']['orderNumber'];

        $orders = static::getContainer()->get(OrderRepository::class);
        $em = $this->em();
        $aliceUser = $em->getRepository(User::class)->find($alice->getId());
        $bob = $this->createUser('bob@example.com');
        $bobUser = $em->getRepository(User::class)->find($bob->getId());

        // Alice retrouve sa commande sous plusieurs écritures de la référence.
        $digits = substr($number, -6);
        foreach ([$number, strtolower($number), str_replace('-', '', $number), $digits, 'GB'.$digits, ' '.$digits.' '] as $ref) {
            self::assertNotNull($orders->findForClientByReference($aliceUser, $ref), $ref);
        }
        // Bob, même avec le bon numéro, ne voit rien : « pas la vôtre » = « inexistante ».
        self::assertNull($orders->findForClientByReference($bobUser, $number));
        self::assertNull($orders->findForClientByReference($bobUser, $digits));
        // Entrées absurdes.
        foreach (['', 'abc', '12', "GB-2026-1' OR 1=1 --", '%', '______'] as $junk) {
            self::assertNull($orders->findForClientByReference($aliceUser, $junk), $junk);
        }
    }

    public function testInstallmentScheduleSumsExactlyToTheTotal(): void
    {
        $this->readyClient();
        // 3 × 15 000 + 10 000 = 55 000 ; en 3 fois = 18 333 + 18 333 + 18 334.
        $this->api('POST', '/commande/valider', $this->body([
            'items' => [['ref' => 'p-2', 'quantity' => 3]],
            'payment' => ['method' => 'installments', 'channel' => 'card', 'installments' => 3],
        ]));
        $order = $this->em()->getRepository(Order::class)->findOneBy([]);

        $detail = static::getContainer()->get(OrderPresenter::class)->detail($order);
        $amounts = array_column($detail['paymentDetails']['schedule'], 'amount');
        self::assertSame([18333, 18333, 18334], $amounts);
        self::assertSame($order->getTotal(), array_sum($amounts));
        self::assertSame('installments', $detail['paymentMethod']);
        self::assertSame("Aujourd'hui", $detail['paymentDetails']['schedule'][0]['label']);
        self::assertSame('2e prélèvement', $detail['paymentDetails']['schedule'][1]['label']);
        self::assertFalse($detail['paymentDetails']['schedule'][0]['isPaid'], 'Aucun encaissement n\'a eu lieu : rien n\'est « payé ».');
    }

    public function testTrackingStepsFollowTheStatus(): void
    {
        $this->readyClient();
        $this->api('POST', '/commande/valider', $this->body());
        // L'admin est créé AVANT de charger la commande (createUser vide l'EntityManager).
        $admin = $this->createUser('admin@example.com', \App\Enum\AccountType::Admin);
        $adminUser = $this->em()->getRepository(User::class)->find($admin->getId());
        $order = $this->em()->getRepository(Order::class)->findOneBy([]);
        $service = static::getContainer()->get(OrderService::class);
        $presenter = static::getContainer()->get(OrderPresenter::class);

        $steps = $presenter->detail($order)['trackingSteps'];
        self::assertSame([true, false, false, false], array_column($steps, 'completed'));
        self::assertSame([false, true, false, false], array_column($steps, 'current'), 'Après la confirmation, « préparation » est l\'étape en cours.');

        $service->advance($order, OrderStatus::Preparing, 'Colis en préparation à l\'entrepôt', $adminUser);
        $service->advance($order, OrderStatus::OutForDelivery, null, $adminUser);
        $steps = $presenter->detail($order)['trackingSteps'];
        self::assertSame([true, true, true, false], array_column($steps, 'completed'));
        self::assertSame([false, false, false, true], array_column($steps, 'current'));
        self::assertSame('Colis en préparation à l\'entrepôt', $steps[1]['description']);

        $service->advance($order, OrderStatus::Delivered, null, $adminUser);
        $steps = $presenter->detail($order)['trackingSteps'];
        self::assertSame([true, true, true, true], array_column($steps, 'completed'));
        self::assertSame([false, false, false, false], array_column($steps, 'current'));
    }

    public function testStatusCannotGoBackwardsOrChangeOnceClosed(): void
    {
        $this->readyClient();
        $this->api('POST', '/commande/valider', $this->body());
        // L'admin est créé AVANT de charger la commande (createUser vide l'EntityManager).
        $admin = $this->createUser('admin@example.com', \App\Enum\AccountType::Admin);
        $adminUser = $this->em()->getRepository(User::class)->find($admin->getId());
        $order = $this->em()->getRepository(Order::class)->findOneBy([]);
        $service = static::getContainer()->get(OrderService::class);

        $service->advance($order, OrderStatus::OutForDelivery, null, $adminUser);
        try {
            $service->advance($order, OrderStatus::Preparing, null, $adminUser);
            self::fail('Un retour en arrière aurait dû être refusé.');
        } catch (OrderRefusedException $e) {
            self::assertSame('order_backwards', $e->reason);
        }

        $service->advance($order, OrderStatus::Cancelled, 'Client injoignable', $adminUser);
        $steps = static::getContainer()->get(OrderPresenter::class)->detail($order)['trackingSteps'];
        self::assertTrue(end($steps)['cancelled']);

        try {
            $service->advance($order, OrderStatus::Delivered, null, $adminUser);
            self::fail('Une commande annulée ne doit plus changer.');
        } catch (OrderRefusedException $e) {
            self::assertSame('order_closed', $e->reason);
        }
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'order.status.changed']));
    }
}
