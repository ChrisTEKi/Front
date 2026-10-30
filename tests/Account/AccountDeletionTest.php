<?php

namespace App\Tests\Account;

use App\Account\AccountPurger;
use App\Entity\AuditLog;
use App\Entity\MobileMoneyAccount;
use App\Entity\Order;
use App\Entity\SocialIdentity;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\AccountType;
use App\Enum\MobileMoneyCarrier;
use App\Enum\OrderStatus;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Order\CatalogItem;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeOidcProvider;
use App\Tests\Support\InMemoryCatalog;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Suppression d'un compte client : demande (gel + délai de 30 jours) puis purge définitive (anonymisation des commandes,
 * épuration du journal d'audit, suppression du compte).
 */
final class AccountDeletionTest extends AppTestCase
{
    private const ADDRESS = ['fullName' => 'Rakoto Jean', 'phone' => '', 'street' => 'Lot II A 124, Ankadifotsy', 'city' => 'Antananarivo', 'postalCode' => '101', 'courierNote' => 'Portail bleu'];

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(InMemoryCatalog::class)->add(new CatalogItem('p-1', 'Casque audio', 120000));
    }

    private function readyClient(string $email = 'c@example.com'): User
    {
        $user = $this->createUser($email, phone: '+261341234567', phoneVerified: true);
        $this->signIn($user);

        return $user;
    }

    /** Passe une commande réelle par l'API et renvoie son numéro. */
    private function placeOrder(): string
    {
        $r = $this->api('POST', '/commande/valider', [
            'items' => [['ref' => 'p-1', 'quantity' => 1]],
            'address' => self::ADDRESS,
            'payment' => ['method' => 'cash_on_delivery'],
            'idempotencyKey' => bin2hex(random_bytes(8)),
        ]);
        self::assertSame(201, $this->httpStatus());

        return $r['order']['orderNumber'];
    }

    private function closeOrder(string $number, OrderStatus $status = OrderStatus::Delivered): void
    {
        $order = $this->em()->getRepository(Order::class)->findOneBy(['orderNumber' => $number]);
        $order->advanceTo($status, null, null, new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();
    }

    /** Lance la purge comme le ferait le cron. */
    private function runPurgeCommand(array $options = []): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:accounts:purge'));
        $tester->execute($options);

        return $tester;
    }

    // ------------------------------------------------------------------ Demande de suppression

    public function testDeletionNeedsTheCorrectPassword(): void
    {
        $user = $this->readyClient();

        $r = $this->api('POST', '/compte/api/suppression', ['password' => 'mauvais']);

        self::assertSame(403, $this->httpStatus());
        self::assertSame('step_up_failed', $r['error']);
        self::assertSame(AccountStatus::Active, $this->reload($user)->getStatus());
    }

    public function testDeletionIsScheduledIn30DaysAndSignsOut(): void
    {
        $user = $this->readyClient();

        $r = $this->api('POST', '/compte/api/suppression', ['password' => self::PASSWORD]);

        self::assertSame(200, $this->httpStatus());
        self::assertEmailCount(1); // avis « suppression programmée » (à vérifier avant la requête suivante : le journal d'e-mails est remis à zéro)
        self::assertTrue($r['ok']);
        self::assertSame('/', $r['redirect']);
        self::assertStringContainsString('définitivement', $r['message']);

        $user = $this->reload($user);
        self::assertSame(AccountStatus::PendingDeletion, $user->getStatus());
        self::assertNotNull($user->getDeletionRequestedAt());
        $days = $user->getDeletionRequestedAt()->diff($user->getDeletionScheduledAt())->days;
        self::assertSame(30, $days);
        self::assertSame($user->getDeletionScheduledAt()->format(\DATE_ATOM), $r['scheduledAt']);

        // La session en cours est fermée.
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects();

        // Se reconnecter propose de restaurer le compte (et l'annule).
        $this->newBrowser();
        $r = $this->api('POST', '/auth/login', ['email' => 'c@example.com', 'password' => self::PASSWORD]);
        self::assertSame('restore_account', $r['step']);
        $r = $this->api('POST', '/auth/restore');
        self::assertSame('done', $r['step']);
        self::assertSame(AccountStatus::Active, $this->reload($user)->getStatus());

        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'account.deletion.requested']));
    }

    public function testDeletionIsRefusedWhileAnOrderIsInProgress(): void
    {
        $user = $this->readyClient();
        $number = $this->placeOrder();

        $r = $this->api('POST', '/compte/api/suppression', ['password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('open_orders', $r['error']);
        self::assertSame(AccountStatus::Active, $this->reload($user)->getStatus());

        // Même refus pour l'envoi d'un code de confirmation : inutile d'envoyer un code qui ne servirait à rien.
        $this->api('POST', '/compte/api/confirmation/envoyer', ['purpose' => 'deletion']);
        self::assertSame(409, $this->httpStatus());

        // Commande livrée : la suppression devient possible.
        $this->closeOrder($number);
        $this->api('POST', '/compte/api/suppression', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
    }

    public function testCancelledOrderDoesNotBlockDeletion(): void
    {
        $this->readyClient();
        $this->closeOrder($this->placeOrder(), OrderStatus::Cancelled);

        $this->api('POST', '/compte/api/suppression', ['password' => self::PASSWORD]);

        self::assertSame(200, $this->httpStatus());
    }

    public function testSuppliersAndAdminsCannotDeleteThemselves(): void
    {
        $supplier = $this->createUser('f@example.com', AccountType::Supplier, phone: '+261341234567', phoneVerified: true);
        $this->signIn($supplier);
        $r = $this->api('POST', '/compte/api/suppression', ['password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('deletion_not_available', $r['error']);
        self::assertSame(AccountStatus::Active, $this->reload($supplier)->getStatus());

        $this->newBrowser();
        $admin = $this->createAdminUser();
        $this->signInAdmin($admin);
        $r = $this->api('POST', '/compte/api/suppression', ['password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('deletion_not_available', $r['error']);
    }

    public function testSocialAccountConfirmsDeletionWithAnEmailedCode(): void
    {
        $idp = static::getContainer()->get(FakeOidcProvider::class);
        $this->client->request('GET', '/auth/social/google');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);
        $idp->claims = FakeOidcProvider::googleClaims($q['nonce']);
        $this->client->request('GET', '/auth/social/google/callback?'.http_build_query(['state' => $q['state'], 'code' => 'abc']));
        $this->refreshCsrf();

        // Sans code : refusé.
        $r = $this->api('POST', '/compte/api/suppression', []);
        self::assertSame('code_required', $r['error']);

        $this->api('POST', '/compte/api/confirmation/envoyer', ['purpose' => 'deletion']);
        self::assertSame(200, $this->httpStatus());
        $code = $this->otp()->lastCode(OtpPurpose::AccountDeletion, OtpChannel::Email);

        $this->api('POST', '/compte/api/suppression', ['code' => $code]);
        self::assertSame(200, $this->httpStatus());
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jean@example.com']);
        self::assertSame(AccountStatus::PendingDeletion, $user->getStatus());
    }

    // ------------------------------------------------------------------ Purge définitive

    /** Client avec commande livrée, adresse, Mobile Money et identité Google, dont la suppression est programmée. */
    private function scheduledClient(): array
    {
        $user = $this->readyClient();
        $number = $this->placeOrder();
        $this->closeOrder($number);

        $em = $this->em();
        $user = $em->getRepository(User::class)->find($user->getId());
        $em->persist(new SocialIdentity($user, \App\Enum\SocialProvider::Google, 'google-sub-xyz', 'c@example.com', new \DateTimeImmutable()));
        $em->persist(new MobileMoneyAccount($user, MobileMoneyCarrier::Mvola, '+261341234567', new \DateTimeImmutable()));
        $em->flush();
        $em->clear();

        // Un échec de connexion ANONYME fait avec cet e-mail (laisse une trace avec e-mail et IP dans le journal).
        $this->newBrowser();
        $this->api('POST', '/auth/login', ['email' => 'c@example.com', 'password' => 'faux'], ip: '198.51.100.9');
        $this->signIn($user);
        $this->api('POST', '/compte/api/suppression', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());

        return [$user->getId()->toRfc4122(), $number];
    }

    public function testNothingIsPurgedBeforeTheGracePeriodEnds(): void
    {
        [$id] = $this->scheduledClient();

        static::mockTime(new \DateTimeImmutable('+29 days'));
        $tester = $this->runPurgeCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('0 compte(s) supprimé(s)', $tester->getDisplay());
        self::assertNotNull($this->em()->getRepository(User::class)->find($id));
    }

    public function testDryRunChangesNothing(): void
    {
        [$id] = $this->scheduledClient();

        static::mockTime(new \DateTimeImmutable('+31 days'));
        $tester = $this->runPurgeCommand(['--dry-run' => true]);

        self::assertStringContainsString('1 compte(s) seraient supprimés', $tester->getDisplay());
        self::assertNotNull($this->em()->getRepository(User::class)->find($id));
    }

    public function testPurgeErasesTheAccountAndAnonymisesTheRest(): void
    {
        [$id, $number] = $this->scheduledClient();
        $em = $this->em();
        self::assertSame(1, $em->getRepository(SocialIdentity::class)->count([]));
        self::assertSame(1, $em->getRepository(MobileMoneyAccount::class)->count([]));

        static::mockTime(new \DateTimeImmutable('+31 days'));
        $tester = $this->runPurgeCommand();
        $em = $this->em();
        $em->clear();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 compte(s) supprimé(s) définitivement', $tester->getDisplay());

        // Le compte et ses dépendances ont disparu (cascade en base).
        self::assertNull($em->getRepository(User::class)->find($id));
        self::assertSame(0, $em->getRepository(SocialIdentity::class)->count([]));
        self::assertSame(0, $em->getRepository(MobileMoneyAccount::class)->count([]));

        // La commande est conservée mais anonymisée.
        $order = $em->getRepository(Order::class)->findOneBy(['orderNumber' => $number]);
        self::assertNotNull($order, 'La commande est conservée pour la comptabilité.');
        self::assertNull($order->getClient());
        self::assertNull($order->getCustomerName());
        self::assertNull($order->getCustomerEmail());
        self::assertNull($order->getCustomerPhone());
        self::assertNull($order->getShippingAddress()['street']);
        self::assertSame('Antananarivo', $order->getShippingAddress()['city']);
        self::assertSame(120000 + 10000, $order->getTotal());

        // Journal d'audit : plus aucune donnée personnelle, mais les lignes restent.
        $logs = $em->getRepository(AuditLog::class)->findAll();
        foreach ($logs as $log) {
            self::assertNotSame('c@example.com', $log->getActorEmail(), 'E-mail effacé du journal : '.$log->getAction());
            self::assertStringNotContainsString('c@example.com', json_encode($log->getMetadata(), \JSON_THROW_ON_ERROR));
        }
        $actions = array_map(static fn (AuditLog $l): string => $l->getAction(), $logs);
        self::assertContains('order.placed', $actions);
        self::assertContains('account.deletion.requested', $actions);
        self::assertContains('auth.login.failed', $actions, 'La ligne anonyme est gardée, sans e-mail ni IP.');
        self::assertContains('account.purged', $actions);
        $failed = array_values(array_filter($logs, static fn (AuditLog $l): bool => 'auth.login.failed' === $l->getAction()))[0];
        self::assertNull($failed->getActorEmail());
        self::assertNull($failed->getIpAddress());
        $purged = array_values(array_filter($logs, static fn (AuditLog $l): bool => 'account.purged' === $l->getAction()))[0];
        self::assertSame($id, $purged->getTargetId());
        self::assertNull($purged->getActorEmail());

        // Dernier e-mail : « votre compte a été définitivement supprimé » (le journal d'e-mails est remis à zéro à chaque
        // nouvelle requête : seul cet envoi reste visible).
        $mails = self::getMailerMessages();
        self::assertCount(1, $mails);
        self::assertEmailHeaderSame($mails[0], 'To', 'Test Client <c@example.com>');
        self::assertSame('GoBuy : votre compte a été définitivement supprimé', $mails[0]->getSubject());

        // Relancer la purge ne fait rien de plus.
        $tester = $this->runPurgeCommand();
        self::assertStringContainsString('0 compte(s) supprimé(s)', $tester->getDisplay());
    }

    public function testRestoredAccountIsNotPurged(): void
    {
        [$id] = $this->scheduledClient();
        $user = $this->em()->getRepository(User::class)->find($id);
        $user->cancelDeletion();
        $this->em()->flush();
        $this->em()->clear();

        static::mockTime(new \DateTimeImmutable('+31 days'));
        $this->runPurgeCommand();

        self::assertNotNull($this->em()->getRepository(User::class)->find($id));
    }

    public function testPurgeWaitsWhileAnOrderIsStillInProgress(): void
    {
        [$id, $number] = $this->scheduledClient();
        // Une commande redevient « en cours » après la demande de suppression (rouverte par le support, par exemple).
        $this->em()->getConnection()->executeStatement("UPDATE orders SET status = 'preparing' WHERE order_number = :n", ['n' => $number]);

        static::mockTime(new \DateTimeImmutable('+31 days'));
        $tester = $this->runPurgeCommand();
        self::assertStringContainsString('0 compte(s) supprimé(s)', $tester->getDisplay());
        self::assertNotNull($this->em()->getRepository(User::class)->find($id), 'Un colis en route : le compte attend.');

        // Commande livrée : au passage suivant, le compte est bien effacé.
        $this->em()->getConnection()->executeStatement("UPDATE orders SET status = 'delivered' WHERE order_number = :n", ['n' => $number]);
        $this->em()->clear();
        $tester = $this->runPurgeCommand();
        self::assertStringContainsString('1 compte(s) supprimé(s) définitivement', $tester->getDisplay());
        self::assertNull($this->em()->getRepository(User::class)->find($id));
    }

    public function testOnlyClientAccountsAreEverPurged(): void
    {
        $supplier = $this->createUser('f@example.com', AccountType::Supplier);
        $supplier = $this->em()->getRepository(User::class)->find($supplier->getId());
        $supplier->requestDeletion(new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();

        static::mockTime(new \DateTimeImmutable('+31 days'));
        $report = static::getContainer()->get(AccountPurger::class)->purgeDue(new \DateTimeImmutable(), 200, false);

        self::assertSame(['due' => 0, 'purged' => 0, 'failed' => 0], $report);
        self::assertNotNull($this->em()->getRepository(User::class)->find($supplier->getId()));
    }
}
