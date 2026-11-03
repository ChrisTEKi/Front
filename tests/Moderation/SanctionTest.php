<?php

namespace App\Tests\Moderation;

use App\Entity\AuditLog;
use App\Entity\BlacklistEntry;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\BlacklistKind;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\Permission;
use App\Enum\ProductStatus;
use App\Moderation\BlacklistService;
use App\Moderation\PayoutGuard;
use App\Order\CatalogItem;
use App\Social\SocialAuthService;
use App\Social\SocialAuthException;
use App\Social\SocialClaims;
use App\Enum\SocialProvider;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\ModerationHelpers;
use App\Tests\Support\InMemoryCatalog;
use App\Tests\Support\SupplierFixtures;

/**
 * Sanctions : bannissement définitif, gel des fonds, remboursement des acheteurs et liste noire.
 */
final class SanctionTest extends AppTestCase
{
    use ModerationHelpers;
    use SupplierFixtures;

    private const REASON = 'Fraude avérée : faux produits vendus à plusieurs clients.';

    // ------------------------------------------------------------------ Aides

    private function ban(User $target, array $extra = []): array
    {
        return $this->api('POST', '/admin/api/comptes/'.$this->id($target).'/bannissement', $extra + ['action' => 'ban', 'reason' => self::REASON, 'password' => self::PASSWORD]);
    }

    private function entries(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(BlacklistEntry::class)->findBy([], ['createdAt' => 'ASC']);
    }

    // ------------------------------------------------------------------ Bannissement

    public function testBanClosesTheAccountAtOnceAndTheHolderCannotSignInAgain(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $this->signInAdmin($this->adminWith(['sanctions.ban']));

        $r = $this->ban($supplier);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('banned', $r['status']);
        $banned = $this->fresh($supplier);
        self::assertTrue($banned->isBanned());
        self::assertSame(self::REASON, $banned->getBanReason());
        self::assertNotNull($banned->getBannedAt());
        self::assertSame('Admin support', $banned->getBannedBy()?->getFullName());
        self::assertContains('admin.account.banned', $this->actions('admin.account'));
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'To', 'Test Fournisseur <marie@boutique.mg>');
        self::assertEmailTextBodyContains(self::getMailerMessage(0), self::REASON);

        $this->newBrowser();
        $r = $this->api('POST', '/auth/login', ['email' => 'marie@boutique.mg', 'password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('account_banned', $r['error']);
        self::assertNotSame('', $r['message']);
        self::assertStringNotContainsString('error.account_banned', $r['message'], 'Le message est traduit, jamais une clé brute.');
    }

    public function testBanIsRefusedWithoutTheRightTheCorrectPasswordOrAReason(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');

        $this->signInAdmin($this->adminWith([], 'sans-droit@example.com'));
        $this->ban($supplier);
        self::assertSame(403, $this->httpStatus(), 'Le profil Support n\'a pas « sanctions.ban ».');

        $this->newBrowser();
        $this->signInAdmin($this->adminWith(['sanctions.ban']));
        $r = $this->ban($supplier, ['password' => 'mauvais-mot-de-passe']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('step_up_failed', $r['error']);

        $r = $this->ban($supplier, ['reason' => 'non']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('reason_required', $r['error']);
        self::assertTrue($this->fresh($supplier)->isActive(), 'Aucun refus ne doit avoir banni le compte.');
    }

    public function testAnAdministratorCanNeverBeTargetedAndAnUnknownAccountIs404(): void
    {
        $other = $this->createAdminUser('autre@example.com', 'finance');
        $this->signInAdmin($this->adminWith(['sanctions.ban', 'sanctions.freeze']));

        $r = $this->ban($other);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('cannot_target_admin', $r['error']);
        self::assertTrue($this->fresh($other)->isActive());

        $this->api('POST', '/admin/api/comptes/'.$this->id($other).'/gel', ['action' => 'freeze', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());

        $this->api('POST', '/admin/api/comptes/00000000-0000-4000-8000-000000000000/bannissement', ['action' => 'ban', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(404, $this->httpStatus());
    }

    public function testEachBanOptionNeedsItsOwnRightAndNothingIsDoneWhenOneIsMissing(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $this->productFor($supplier, 'Casque', 1000);
        $this->signInAdmin($this->adminWith(['sanctions.ban']));

        foreach ([['catalog' => 'remove'], ['catalog' => 'delete'], ['refund' => 'open'], ['blacklist' => ['email']]] as $option) {
            $r = $this->ban($supplier, $option);
            self::assertSame(403, $this->httpStatus(), json_encode($option));
            self::assertSame('forbidden', $r['error']);
        }
        self::assertTrue($this->fresh($supplier)->isActive());

        $r = $this->ban($supplier, ['catalog' => 'tout-casser']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_option', $r['error']);
    }

    public function testBanCanRemoveTheCatalogueRefundOpenOrdersAndBlacklistTheIdentifiers(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'approved', '+261331112233', 'Ma Boutique');
        $other = $this->supplierAccount('paul@boutique.mg', 'approved', '+261341112244', 'Boutique Paul');
        $buyer = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $this->productFor($supplier, 'Casque', 1000);
        $this->productFor($supplier, 'Montre', 2000);
        $open = $this->orderFor($buyer, [[$supplier, 'Casque', 50000, 2]], OrderStatus::Preparing, PaymentStatus::Paid);
        $delivered = $this->orderFor($buyer, [[$supplier, 'Montre', 30000, 1]], OrderStatus::Delivered);
        $mixed = $this->orderFor($buyer, [[$supplier, 'Casque', 20000, 1], [$other, 'Sac', 10000, 1]]);

        $this->signInAdmin($this->createSuperAdmin());
        $r = $this->ban($supplier, ['catalog' => 'remove', 'refund' => 'open', 'blacklist' => ['email', 'phone', 'name', 'company']]);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, $r['catalog']);
        self::assertSame(2, $r['refund']['orders']);
        self::assertEqualsCanonicalizing(['email', 'phone', 'name', 'company'], $r['blacklist']);

        // Catalogue retiré de la vente (réversible).
        $this->em()->clear();
        foreach ($this->em()->getRepository(Product::class)->findAll() as $product) {
            self::assertSame(ProductStatus::Removed, $product->getStatus());
        }

        // Commande entièrement au fournisseur et en cours : annulée, rendue en entier (livraison comprise), paiement « remboursé ».
        $o = $this->order($open);
        self::assertSame(OrderStatus::Cancelled, $o->getStatus());
        self::assertSame(110000, $o->getRefundedAmount());
        self::assertSame(PaymentStatus::Refunded, $o->getPaymentStatus());
        // Commande livrée : hors périmètre « open ».
        self::assertSame(0, $this->order($delivered)->getRefundedAmount());
        // Commande mixte : seule la ligne du fournisseur banni est remboursée, la commande continue pour l'autre article.
        $m = $this->order($mixed);
        self::assertSame(20000, $m->getRefundedAmount());
        self::assertSame(OrderStatus::Confirmed, $m->getStatus());
        self::assertFalse($m->isFullyRefunded());

        // Liste noire : valeurs normalisées.
        $values = [];
        foreach ($this->entries() as $entry) {
            $values[$entry->getKind()->value] = $entry->getValue();
        }
        self::assertSame('marie@boutique.mg', $values['email']);
        self::assertSame('+261331112233', $values['phone']);
        self::assertSame('ma boutique', $values['company']);
        self::assertSame('fournisseur test', $values['name']);

        // E-mails : le titulaire + UN seul e-mail pour l'acheteur (qui a deux commandes concernées).
        self::assertEmailCount(2);
        $to = array_map(static fn ($m): string => $m->getTo()[0]->getAddress(), array_map(static fn ($e) => $e, self::getMailerMessages()));
        self::assertEqualsCanonicalizing(['marie@boutique.mg', 'acheteur@example.com'], $to);

        $audit = $this->actions('admin.');
        self::assertContains('admin.account.banned', $audit);
        self::assertContains('admin.refunds.issued', $audit);
        self::assertContains('admin.catalog.removed', $audit);
    }

    public function testBanCanDeleteTheWholeCatalogue(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $this->productFor($supplier, 'Casque', 1000);
        $this->productFor($supplier, 'Montre', 2000);
        $this->signInAdmin($this->createSuperAdmin());

        $r = $this->ban($supplier, ['catalog' => 'delete']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, $r['catalog']);
        $this->em()->clear();
        self::assertSame(0, $this->em()->getRepository(Product::class)->count([]));
    }

    public function testBanningAClientCancelsTheirOpenOrdersAndRefusesCatalogueOptions(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $client = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $open = $this->orderFor($client, [[$supplier, 'Casque', 50000, 1]]);
        $done = $this->orderFor($client, [[$supplier, 'Montre', 30000, 1]], OrderStatus::Delivered);
        $this->signInAdmin($this->createSuperAdmin());

        $r = $this->ban($client, ['catalog' => 'remove']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('not_a_supplier', $r['error']);
        self::assertTrue($this->fresh($client)->isActive());

        $r = $this->ban($client, ['refund' => 'open']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(1, $r['refund']['orders']);
        self::assertSame(OrderStatus::Cancelled, $this->order($open)->getStatus());
        self::assertSame(OrderStatus::Delivered, $this->order($done)->getStatus());
        self::assertSame(0, $this->order($done)->getRefundedAmount());

        // Déjà banni.
        $this->ban($client);
        self::assertSame(409, $this->httpStatus());
    }

    public function testOnlyTheSuperAdminCanLiftABan(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $super = $this->createSuperAdmin();
        $this->signInAdmin($this->adminWith(['sanctions.ban']));
        $this->ban($supplier);
        $url = '/admin/api/comptes/'.$this->id($supplier).'/bannissement';

        $r = $this->api('POST', $url, ['action' => 'unban', 'reason' => 'Erreur de notre part, désolé.', 'password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('only_super_admin', $r['error']);
        self::assertTrue($this->fresh($supplier)->isBanned());

        $this->newBrowser();
        $this->signInAdmin($super);
        $this->api('POST', $url, ['action' => 'unban', 'reason' => '', 'password' => self::PASSWORD]);
        self::assertSame(422, $this->httpStatus(), 'Une justification est obligatoire.');

        $this->api('POST', $url, ['action' => 'unban', 'reason' => 'Erreur de notre part, désolé.', 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        $back = $this->fresh($supplier);
        self::assertTrue($back->isActive());
        self::assertNull($back->getBannedAt());
        self::assertNull($back->getBanReason());
        self::assertContains('admin.account.unbanned', $this->actions('admin.account'));

        $this->newBrowser();
        $this->api('POST', '/auth/login', ['email' => 'marie@boutique.mg', 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus(), 'Le compte rétabli peut de nouveau se connecter.');

        $this->newBrowser();
        $this->signInAdmin($super);
        $this->api('POST', $url, ['action' => 'unban', 'reason' => 'Erreur de notre part, désolé.', 'password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
    }

    public function testAnOpenSessionIsClosedWhenTheAccountIsBanned(): void
    {
        $client = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $this->signIn($client);
        $this->client->request('GET', '/compte');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Le bannissement est prononcé par un autre navigateur (celui de l'administrateur).
        $cookies = $this->client->getCookieJar()->all();
        $this->newBrowser();
        $this->signInAdmin($this->createSuperAdmin());
        $this->ban($this->fresh($client));
        self::assertSame(200, $this->httpStatus());

        $this->newBrowser();
        foreach ($cookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->client->request('GET', '/compte');
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode(), 'La session d\'un compte banni ne doit plus ouvrir « Mon compte ».');
    }

    // ------------------------------------------------------------------ Gel conservatoire

    public function testFreezeHidesTheSuppliersShopAndBlocksPayoutsUntilItIsLifted(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $product = $this->productFor($supplier, 'Casque', 1000);
        $page = '/produit/'.$product->getId()->toRfc4122();
        $this->client->request('GET', $page);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue((new PayoutGuard())->canPayOut($this->fresh($supplier)));

        $this->signInAdmin($this->adminWith(['sanctions.freeze']));
        $url = '/admin/api/comptes/'.$this->id($supplier).'/gel';

        $this->api('POST', $url, ['action' => 'freeze', 'reason' => 'x', 'password' => self::PASSWORD]);
        self::assertSame(422, $this->httpStatus());
        $this->api('POST', $url, ['action' => 'freeze', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertEmailCount(1);

        $frozen = $this->fresh($supplier);
        self::assertTrue($frozen->isFundsFrozen());
        self::assertTrue($frozen->isActive(), 'Le compte reste connectable : il peut s\'expliquer.');
        self::assertFalse((new PayoutGuard())->canPayOut($frozen));
        $this->client->request('GET', $page);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Plus de vente tant que les fonds sont gelés.');

        $this->api('POST', $url, ['action' => 'freeze', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());

        $this->api('POST', $url, ['action' => 'unfreeze', 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertTrue((new PayoutGuard())->canPayOut($this->fresh($supplier)));
        $this->client->request('GET', $page);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->api('POST', $url, ['action' => 'unfreeze', 'password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame(['admin.funds.frozen', 'admin.funds.unfrozen'], $this->actions('admin.funds'));
    }

    public function testFrozenSupplierSeesAWarningAndFrozenClientCannotOrder(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $client = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        static::getContainer()->get(InMemoryCatalog::class)->add(new CatalogItem('p1', 'Robe wax', 50000, $supplier->getId()));

        $this->signInAdmin($this->createSuperAdmin());
        foreach ([$supplier, $client] as $target) {
            $this->api('POST', '/admin/api/comptes/'.$this->id($target).'/gel', ['action' => 'freeze', 'reason' => self::REASON, 'password' => self::PASSWORD]);
            self::assertSame(200, $this->httpStatus());
        }

        $this->newBrowser();
        $this->signIn($supplier);
        $this->client->request('GET', '/fournisseur');
        self::assertStringContainsString('data-funds-frozen', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString(self::REASON, html_entity_decode((string) $this->client->getResponse()->getContent()));

        $this->newBrowser();
        $this->signIn($client);
        $r = $this->api('POST', '/commande/valider', [
            'items' => [['ref' => 'p1', 'quantity' => 1]],
            'address' => ['fullName' => 'Rakoto', 'phone' => '', 'street' => 'Rue 9', 'city' => 'Antananarivo', 'postalCode' => '101', 'courierNote' => ''],
            'payment' => ['method' => 'cash_on_delivery'],
            'idempotencyKey' => 'frozen-client-1',
        ]);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('funds_frozen', $r['error']);
    }

    // ------------------------------------------------------------------ Remboursements

    public function testRefundEndpointRefundsOnlyTheSuppliersLinesAndIsIdempotent(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $other = $this->supplierAccount('paul@boutique.mg', 'approved', '+261341112244', 'Boutique Paul');
        $buyer = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $mixed = $this->orderFor($buyer, [[$supplier, 'Casque', 20000, 1], [$other, 'Sac', 10000, 1]]);
        $delivered = $this->orderFor($buyer, [[$supplier, 'Montre', 30000, 1]], OrderStatus::Delivered, PaymentStatus::Paid);
        $url = '/admin/api/comptes/'.$this->id($supplier).'/remboursements';

        $this->signInAdmin($this->adminWith(['sanctions.refund']));
        $r = $this->api('POST', $url, ['scope' => 'open', 'reason' => self::REASON, 'password' => 'faux']);
        self::assertSame(403, $this->httpStatus());

        $r = $this->api('POST', $url, ['scope' => 'weekly', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_scope', $r['error']);

        $r = $this->api('POST', $url, ['scope' => 'open', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['orders' => 1, 'buyers' => 1, 'amount' => 20000, 'remaining' => 0], $r['refund']);
        self::assertSame(20000, $this->order($mixed)->getRefundedAmount());
        self::assertSame(0, $this->order($delivered)->getRefundedAmount());

        // Deuxième passage identique : plus rien à rembourser (aucun double remboursement).
        $this->api('POST', $url, ['scope' => 'open', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame(20000, $this->order($mixed)->getRefundedAmount());

        // Périmètre « all » : la commande livrée et payée est remboursée à son tour.
        $r = $this->api('POST', $url, ['scope' => 'all', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(40000, $r["refund"]["amount"], "Commande livrée : 30 000 + 10 000 de livraison.");
        $d = $this->order($delivered);
        self::assertTrue($d->isFullyRefunded());
        self::assertSame(OrderStatus::Delivered, $d->getStatus(), 'Une commande livrée n\'est pas annulée.');
        self::assertSame(PaymentStatus::Refunded, $d->getPaymentStatus());
    }

    public function testRefundNeedsTheRightAndAGoodTarget(): void
    {
        $client = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $this->signInAdmin($this->adminWith([]));
        $this->api('POST', '/admin/api/comptes/'.$this->id($client).'/remboursements', ['scope' => 'open', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());

        $this->newBrowser();
        $this->signInAdmin($this->adminWith(['sanctions.refund'], 'finance2@example.com'));
        $r = $this->api('POST', '/admin/api/comptes/'.$this->id($client).'/remboursements', ['scope' => 'open', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('not_a_supplier', $r['error']);
    }

    // ------------------------------------------------------------------ Liste noire

    public function testBlacklistedDataCannotRegisterAndTheMessageNeverSaysWhy(): void
    {
        $this->signInAdmin($this->adminWith(['blacklist.manage']));
        $add = fn (string $kind, string $value, array $more = []) => $this->api('POST', '/admin/api/liste-noire', ['kind' => $kind, 'value' => $value, 'reason' => 'Fraudeur connu', 'password' => self::PASSWORD] + $more);

        $add('email', 'Fraud.eur+promo@Gmail.com');
        $add('phone', '034 99 888 77');
        $add('name', 'Dupont Jean');
        $add('ip', '203.0.113.99', ['days' => 30]);
        $this->newBrowser();

        $base = ['fullName' => 'Rakoto Paul', 'email' => 'rakoto@example.com', 'password' => self::PASSWORD, 'phone' => ''];
        $messages = [];
        foreach ([
            ['email' => 'fraudeur@googlemail.com'],          // variante Gmail : points, +étiquette et googlemail
            ['phone' => '+261349988877'],                    // même numéro écrit autrement
            ['fullName' => 'JEAN dupont'],                   // nom dans un autre ordre, autre casse
        ] as $change) {
            $r = $this->api('POST', '/auth/register/client', $change + $base);
            self::assertSame(403, $this->httpStatus(), json_encode($change));
            self::assertSame('registration_blocked', $r['error']);
            $messages[] = $r['message'];
        }
        self::assertCount(1, array_unique($messages), 'Le même message pour toutes les causes.');

        // Depuis l'adresse IP interdite.
        $r = $this->api('POST', '/auth/register/client', $base, ip: '203.0.113.99');
        self::assertSame(403, $this->httpStatus());

        // Une inscription sans rapport reste possible.
        $this->api('POST', '/auth/register/client', $base, ip: '198.51.100.20');
        self::assertSame(200, $this->httpStatus());
        self::assertContains('security.blacklist.hit', $this->actions('security.'));
    }

    public function testBlacklistedCompanyNameBlocksASupplierSignUp(): void
    {
        $this->signInAdmin($this->adminWith(['blacklist.manage']));
        $this->api('POST', '/admin/api/liste-noire', ['kind' => 'company', 'value' => 'Boutique  Marie', 'reason' => 'Faux vendeur', 'password' => self::PASSWORD]);
        self::assertSame(201, $this->httpStatus());
        $this->newBrowser();

        $r = $this->api('POST', '/auth/register/supplier', [
            'fullName' => 'Razafy Marie', 'email' => 'marie@boutique.mg', 'password' => self::PASSWORD, 'phone' => '033 12 345 67',
            'companyName' => 'BOUTIQUE marie', 'companyAddress' => 'Lot 12 Analakely, Antananarivo', 'companyDescription' => 'Vêtements',
        ]);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('registration_blocked', $r['error']);
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['accountType' => AccountType::Supplier]));
    }

    public function testBlacklistValidationAndNormalisation(): void
    {
        $this->signInAdmin($this->adminWith(['blacklist.manage']));
        $post = fn (array $d) => $this->api('POST', '/admin/api/liste-noire', $d + ['reason' => 'Fraudeur connu', 'password' => self::PASSWORD]);

        $r = $post(['kind' => 'email', 'value' => 'pas-un-email']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_blacklist_value', $r['error']);

        $r = $post(['kind' => 'name', 'value' => 'Rakoto']);
        self::assertSame(422, $this->httpStatus(), 'Un seul mot ferait trop d\'homonymes.');

        $r = $post(['kind' => 'ip', 'value' => '203.0.113.5']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('ip_needs_expiry', $r['error']);

        $r = $post(['kind' => 'ip', 'value' => '203.0.113.5', 'days' => '400']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_days', $r['error']);

        $post(['kind' => 'wat', 'value' => 'x']);
        self::assertSame(422, $this->httpStatus());

        $post(['kind' => 'email', 'value' => 'a@b.com', 'reason' => 'x']);
        self::assertSame(422, $this->httpStatus(), 'Un motif est obligatoire.');

        $post(['kind' => 'email', 'value' => 'a@b.com', 'password' => 'faux']);
        self::assertSame(403, $this->httpStatus());
        self::assertCount(0, $this->entries());

        // Même valeur sous deux écritures : UNE seule entrée, renouvelée.
        $post(['kind' => 'email', 'value' => 'J.Dupont+a@gmail.com']);
        $post(['kind' => 'email', 'value' => 'jdupont@googlemail.com', 'reason' => 'Nouveau motif connu']);
        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame('jdupont@gmail.com', $entries[0]->getValue());
        self::assertSame('Nouveau motif connu', $entries[0]->getReason());
    }

    public function testNormalizationRules(): void
    {
        $service = static::getContainer()->get(BlacklistService::class);

        self::assertSame('jdupont@gmail.com', $service->normalize(BlacklistKind::Email, ' J.Dupont+x@GoogleMail.com '));
        self::assertSame('a.b@exemple.mg', $service->normalize(BlacklistKind::Email, 'A.B+z@exemple.mg'), 'Les points ne comptent que chez Gmail.');
        self::assertNull($service->normalize(BlacklistKind::Email, 'pas-un-email'));
        self::assertSame('+261341234567', $service->normalize(BlacklistKind::Phone, '034 12 345 67'));
        self::assertNull($service->normalize(BlacklistKind::Phone, '12'));
        self::assertSame('dupont jean', $service->normalize(BlacklistKind::Name, 'JEAN  Dupont'));
        self::assertSame('andriamasy rene', $service->normalize(BlacklistKind::Name, 'René  Andriamasy'));
        self::assertNull($service->normalize(BlacklistKind::Name, 'Madonna'));
        self::assertSame('ma boutique', $service->normalize(BlacklistKind::Company, 'Ma  Boutique !'));
        self::assertSame('2001:db8::1', $service->normalize(BlacklistKind::Ip, '2001:0DB8:0000:0000:0000:0000:0000:0001'));
        self::assertNull($service->normalize(BlacklistKind::Ip, '999.1.1.1'));
    }

    public function testExpiredEntriesNoLongerBlock(): void
    {
        $this->signInAdmin($this->adminWith(['blacklist.manage']));
        $this->api('POST', '/admin/api/liste-noire', ['kind' => 'email', 'value' => 'fraudeur@example.com', 'days' => 2, 'reason' => 'Fraudeur connu', 'password' => self::PASSWORD]);
        self::assertSame(201, $this->httpStatus());
        $this->newBrowser();

        $payload = ['fullName' => 'Rakoto Paul', 'email' => 'fraudeur@example.com', 'password' => self::PASSWORD, 'phone' => ''];
        $this->api('POST', '/auth/register/client', $payload);
        self::assertSame(403, $this->httpStatus());

        static::mockTime((new \DateTimeImmutable())->modify('+3 days'));
        $this->api('POST', '/auth/register/client', $payload);
        self::assertSame(200, $this->httpStatus(), 'L\'entrée a expiré : l\'inscription est de nouveau possible.');
    }

    public function testBlacklistCanBeRemovedAndTheRoutesNeedTheRight(): void
    {
        $this->signInAdmin($this->adminWith([]));
        $this->client->request('GET', '/admin/liste-noire');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->api('POST', '/admin/api/liste-noire', ['kind' => 'email', 'value' => 'a@b.com', 'reason' => 'Fraudeur connu', 'password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());

        $this->newBrowser();
        $this->signInAdmin($this->adminWith(['blacklist.manage'], 'liste@example.com'));
        $r = $this->api('POST', '/admin/api/liste-noire', ['kind' => 'email', 'value' => 'a@b.com', 'reason' => 'Fraudeur connu', 'password' => self::PASSWORD]);
        self::assertSame(201, $this->httpStatus());

        $this->client->request('GET', '/admin/liste-noire');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-blacklist-entry="'.$r['id'].'"', $html);
        self::assertStringContainsString('a@b.com', $html);

        $this->api('POST', '/admin/api/liste-noire/'.$r['id'].'/supprimer', ['password' => 'faux']);
        self::assertSame(403, $this->httpStatus());
        $this->api('POST', '/admin/api/liste-noire/'.$r['id'].'/supprimer', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertCount(0, $this->entries());
        $this->api('POST', '/admin/api/liste-noire/'.$r['id'].'/supprimer', ['password' => self::PASSWORD]);
        self::assertSame(404, $this->httpStatus());
        self::assertSame(['admin.blacklist.added', 'admin.blacklist.removed'], $this->actions('admin.blacklist'));
    }

    public function testGoogleOrAppleSignUpIsBlockedByTheBlacklistButExistingAccountsAreNotAffected(): void
    {
        $this->signInAdmin($this->adminWith(['blacklist.manage']));
        $this->api('POST', '/admin/api/liste-noire', ['kind' => 'email', 'value' => 'fraudeur@example.com', 'reason' => 'Fraudeur connu', 'password' => self::PASSWORD]);
        $this->newBrowser();

        $service = static::getContainer()->get(SocialAuthService::class);
        try {
            $service->resolveUser(new SocialClaims(SocialProvider::Google, 'sub-1', 'fraudeur@example.com', true, 'Rakoto Paul'), 'fr');
            self::fail('Un compte ne doit pas être créé pour une adresse sur la liste noire.');
        } catch (SocialAuthException $e) {
            self::assertSame('registration_blocked', $e->reason);
        }
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['accountType' => AccountType::Client]));

        $user = $service->resolveUser(new SocialClaims(SocialProvider::Google, 'sub-2', 'ok@example.com', true, 'Rakoto Paul'), 'fr');
        self::assertSame('ok@example.com', $user->getEmail());
    }

    // ------------------------------------------------------------------ Fiche du compte

    public function testAccountPageShowsOnlyThePanelsTheAdministratorMayUse(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $this->signInAdmin($this->adminWith(['suppliers.view', 'sanctions.freeze', 'sanctions.refund']));

        $this->client->request('GET', '/admin/comptes/'.$this->id($supplier));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-panel="freeze"', $html);
        self::assertStringContainsString('data-panel="refund"', $html);
        self::assertStringNotContainsString('data-panel="ban"', $html, 'Sans « sanctions.ban », pas de formulaire de bannissement.');

        $this->newBrowser();
        $this->signInAdmin($this->adminWith(['suppliers.view', 'sanctions.ban', 'blacklist.manage', 'products.remove'], 'banni@example.com'));
        $this->client->request('GET', '/admin/comptes/'.$this->id($supplier));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-panel="ban"', $html);
        self::assertStringContainsString('name="blacklist[]"', $html);
        self::assertStringContainsString('value="remove"', $html);
        self::assertStringNotContainsString('value="delete"', $html, 'Supprimer le catalogue exige « products.delete ».');
        self::assertStringNotContainsString('data-panel="refund"', $html);
    }

    public function testBannedAccountPageShowsTheBannerAndOnlyTheSuperAdminSeesTheLiftForm(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $this->signInAdmin($this->createSuperAdmin());
        $this->ban($supplier);

        $this->client->request('GET', '/admin/comptes/'.$this->id($supplier));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-ban-banner', $html);
        self::assertStringContainsString('data-panel="unban"', $html);
        self::assertStringNotContainsString('data-panel="ban"', $html);
        self::assertStringContainsString('Banni', $html);

        $this->newBrowser();
        $this->signInAdmin($this->adminWith(['suppliers.view', 'sanctions.ban']));
        $this->client->request('GET', '/admin/comptes/'.$this->id($supplier));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-ban-banner', $html);
        self::assertStringNotContainsString('data-panel="unban"', $html);
    }

    public function testNewTextsExistInEveryLanguage(): void
    {
        $translator = static::getContainer()->get('translator');
        foreach (['fr', 'en', 'es'] as $locale) {
            foreach ([
                ['sanction.ban.submit', 'sanctions'], ['sanction.refund.help', 'sanctions'], ['blacklist.title', 'sanctions'], ['error.only_super_admin', 'sanctions'],
                ['perm.sanctions.ban', 'admin'], ['perm.blacklist.manage', 'admin'], ['tabs.blacklist', 'admin'],
                ['error.account_banned', 'auth'], ['error.registration_blocked', 'auth'], ['error.funds_frozen', 'orders'],
                ['moderation.account_banned.body', 'emails'], ['moderation.order_refunded.body', 'emails'], ['moderation.funds_frozen.subject', 'emails'],
            ] as [$key, $domain]) {
                self::assertNotSame($key, $translator->trans($key, [], $domain, $locale), $locale.' '.$domain.' '.$key);
            }
        }
    }
}
