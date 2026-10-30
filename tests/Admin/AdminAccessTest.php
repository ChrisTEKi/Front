<?php

namespace App\Tests\Admin;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\AccountType;
use App\Enum\OrderStatus;
use App\Enum\SupplierStatus;
use App\Order\CatalogItem;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\InMemoryCatalog;

/**
 * Espace administrateur : qui voit quoi, qui peut faire quoi.
 *
 * Le principe testé partout : un administrateur n'a QUE les droits de son profil (+ ajouts − retraits).
 * Un droit manquant se traduit par un refus 403 côté serveur ET par une donnée absente de la page (pas seulement cachée).
 */
final class AdminAccessTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(InMemoryCatalog::class)->add(new CatalogItem('p-1', 'Casque audio', 120000));
    }

    // ------------------------------------------------------------------ Aides

    /** Un fournisseur avec son dossier « en attente » ; le test choisit ce qui est vérifié. */
    private function pendingSupplier(string $email = 'boutique@example.com', bool $emailVerified = true, bool $phoneVerified = true, string $phone = '+261331112233'): User
    {
        $user = $this->createUser($email, AccountType::Supplier, $emailVerified, $phone, $phoneVerified);
        $this->em()->persist(new SupplierProfile($this->reload($user), 'Ma Boutique SARL', 'Lot 12 Analakely', 'Vêtements', new \DateTimeImmutable()));
        $this->em()->flush();
        $this->em()->clear();

        return $user;
    }

    /** Une commande passée par un vrai client via l'API, puis on change de navigateur. @return string numéro de commande */
    private function orderNumber(): string
    {
        $client = $this->createUser('acheteur@example.com', phone: '+261341234567', phoneVerified: true);
        $this->signIn($client);
        $r = $this->api('POST', '/commande/valider', [
            'items' => [['ref' => 'p-1', 'quantity' => 1]],
            'address' => ['fullName' => 'Rakoto Jean', 'phone' => '', 'street' => 'Lot II A 124', 'city' => 'Antananarivo', 'postalCode' => '101', 'courierNote' => ''],
            'payment' => ['method' => 'cash_on_delivery'],
            'idempotencyKey' => 'admin-test-1',
        ]);
        $this->newBrowser();

        return $r['order']['orderNumber'];
    }

    private function adminPage(string $query = ''): string
    {
        $this->client->request('GET', '/admin'.$query);
        $this->em()->clear();
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    // ------------------------------------------------------------------ Dossier détaillé d'un fournisseur

    public function testAdminReadsTheSupplierApplicationInItsOwnLanguageAndItIsEscaped(): void
    {
        $user = $this->createUser('boutique@example.com', AccountType::Supplier, true, '+261331112233', true);
        $this->em()->persist(new SupplierProfile($this->reload($user), 'Ma Boutique SARL', 'Lot 12 Analakely', null, new \DateTimeImmutable(), [
            'city' => 'Mahajanga', 'mainCategory' => 'cat-beaute', 'plan' => 'pro', 'productTypes' => ['beauty', 'toys'], 'prepTime' => 'same_day',
            'ownDelivery' => false, 'damage' => true, 'proofs' => ['police_report'], 'expectations' => '<img src=x onerror=alert(1)>',
        ]));
        $this->em()->flush();
        $this->em()->clear();
        $this->signInAdmin($this->createAdminUser('modo@example.com', 'moderation'));

        $html = $this->adminPage();
        self::assertStringContainsString('Mahajanga', $html);
        self::assertStringContainsString('Beauté &amp; Cosmétique', $html);
        self::assertStringContainsString('Beauté, Jouets', $html);
        self::assertStringContainsString('Dépôt de plainte', $html);
        self::assertStringContainsString('Pro (49 000 Ar/mois)', $html);
        // Le texte libre du candidat est échappé : jamais de balise exécutable.
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testSupplierWithoutApplicationShowsAnExplicitNote(): void
    {
        $this->pendingSupplier();
        $this->signInAdmin($this->createAdminUser('modo@example.com', 'moderation'));

        self::assertStringContainsString('Dossier sans détail', $this->adminPage());
    }

    // ------------------------------------------------------------------ Accès à la page

    public function testVisitorIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects();
    }

    public function testClientAndSupplierAreForbidden(): void
    {
        $this->signIn($this->createUser('client@example.com'));
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);

        $this->newBrowser();
        $this->signIn($this->createUser('fourn@example.com', AccountType::Supplier, true, '+261331112233', true));
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testActionsAreForbiddenToNonAdmins(): void
    {
        $client = $this->createUser('client@example.com');
        $this->signIn($client);

        $this->api('POST', '/admin/api/utilisateurs/'.$client->getId()->toRfc4122().'/statut', ['action' => 'suspend']);
        self::assertSame(403, $this->httpStatus());
        $this->api('POST', '/admin/api/administrateurs', ['email' => 'x@example.com']);
        self::assertSame(403, $this->httpStatus());
    }

    // ------------------------------------------------------------------ Chaque onglet suit le droit correspondant

    public function testFinanceAdminSeesOrdersButNotCustomers(): void
    {
        $this->createUser('secret-client@example.com');
        $number = $this->orderNumber();
        $this->signInAdmin($this->createAdminUser('fin@example.com', 'finance'));

        $html = $this->adminPage();

        self::assertStringContainsString('data-admin-tab="orders_table"', $html);
        self::assertStringContainsString($number, $html);
        // Pas de droit « users.view » : ni l'onglet, ni la moindre donnée client ne figure dans la page.
        self::assertStringNotContainsString('data-admin-tab="users_table"', $html);
        self::assertStringNotContainsString('secret-client@example.com', $html);
        // Pas de droit d'administration : ni onglet, ni formulaires de gestion.
        self::assertStringNotContainsString('data-admin-tab="admins"', $html);
        self::assertStringNotContainsString('data-dialog="admin"', $html);
        self::assertStringNotContainsString('data-admin-tab="audit"', $html);
    }

    public function testSupportAdminSeesCustomersButCannotExportThem(): void
    {
        $this->createUser('client-visible@example.com');
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));

        $html = $this->adminPage('?tab=users_table');
        self::assertStringContainsString('client-visible@example.com', $html);
        self::assertStringNotContainsString('action="/admin/export/', $html, 'Pas de droit d\'export : aucun formulaire d\'export n\'est dessiné.');

        // Le serveur refuse aussi si on forge la requête.
        $this->client->request('POST', '/admin/export/clients', ['_token' => 'peu-importe']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testListOfAdministratorsIsNotAvailableByChangingTheUrl(): void
    {
        $this->createAdminUser('autre@example.com', 'moderation');
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));

        $html = $this->adminPage('?tab=users_table&role=admin');

        self::assertStringNotContainsString('autre@example.com', $html, 'Seul le Super Admin voit la liste des administrateurs.');
    }

    public function testSuperAdminSeesEveryTab(): void
    {
        $this->signInAdmin($this->createSuperAdmin());

        $html = $this->adminPage();

        foreach (['overview', 'vendor_applications', 'vendor_payouts', 'suppliers_approval', 'users_table', 'orders_table', 'admins', 'roles', 'audit', 'exports'] as $tab) {
            self::assertStringContainsString('data-admin-tab="'.$tab.'"', $html, 'Onglet manquant : '.$tab);
        }
    }

    public function testSearchAndPaginationOfUsers(): void
    {
        $this->createUser('alice@example.com');
        $this->createUser('bob@example.com');
        $this->createUser('zoe-fournisseur@example.com', AccountType::Supplier, true, '+261331112233', true);
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));

        $html = $this->adminPage('?tab=users_table&q=ALI');
        self::assertStringContainsString('alice@example.com', $html, 'La recherche ignore la casse.');
        self::assertStringNotContainsString('bob@example.com', $html);

        $html = $this->adminPage('?tab=users_table&role=supplier');
        self::assertStringContainsString('zoe-fournisseur@example.com', $html);
        self::assertStringNotContainsString('alice@example.com', $html, 'Le filtre de type de compte est respecté.');

        // Les jokers saisis par l'utilisateur n'ouvrent pas la recherche à tout le monde.
        $html = $this->adminPage('?tab=users_table&q=%25');
        self::assertStringNotContainsString('alice@example.com', $html);
    }

    // ------------------------------------------------------------------ Validation des fournisseurs

    public function testModerationAdminApprovesAVerifiedSupplier(): void
    {
        $supplier = $this->pendingSupplier();
        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));

        $r = $this->api('POST', '/admin/api/fournisseurs/'.$supplier->getId()->toRfc4122().'/decision', ['action' => 'approve']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('approved', $r['status']);
        $profile = $this->em()->getRepository(SupplierProfile::class)->findOneBy([]);
        self::assertSame(SupplierStatus::Approved, $profile->getStatus());
        self::assertNotNull($profile->getReviewedBy());
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.supplier.approved']));
    }

    public function testSupplierWithUnverifiedPhoneCannotBeApproved(): void
    {
        $supplier = $this->pendingSupplier(phoneVerified: false);
        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));

        $r = $this->api('POST', '/admin/api/fournisseurs/'.$supplier->getId()->toRfc4122().'/decision', ['action' => 'approve']);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('supplier_not_verified', $r['error']);
        self::assertSame(SupplierStatus::Pending, $this->em()->getRepository(SupplierProfile::class)->findOneBy([])->getStatus());
    }

    public function testRejectionNeedsAWrittenReasonAndHappensOnce(): void
    {
        $supplier = $this->pendingSupplier();
        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));
        $uri = '/admin/api/fournisseurs/'.$supplier->getId()->toRfc4122().'/decision';

        $r = $this->api('POST', $uri, ['action' => 'reject', 'reason' => 'non']);
        self::assertSame('reason_required', $r['error']);

        $this->api('POST', $uri, ['action' => 'reject', 'reason' => 'Documents illisibles, merci de redéposer le dossier.']);
        self::assertSame(200, $this->httpStatus());
        $profile = $this->em()->getRepository(SupplierProfile::class)->findOneBy([]);
        self::assertSame(SupplierStatus::Rejected, $profile->getStatus());
        self::assertSame('Documents illisibles, merci de redéposer le dossier.', $profile->getRejectionReason());

        $r = $this->api('POST', $uri, ['action' => 'reject', 'reason' => 'Une seconde fois, sans effet.']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('invalid_transition', $r['error']);
    }

    public function testUnknownDecisionIsRefused(): void
    {
        $supplier = $this->pendingSupplier();
        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));

        $r = $this->api('POST', '/admin/api/fournisseurs/'.$supplier->getId()->toRfc4122().'/decision', ['action' => 'delete-everything']);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_action', $r['error']);
    }

    public function testAdminWithoutApprovalRightCannotDecide(): void
    {
        $supplier = $this->pendingSupplier();
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));

        $this->api('POST', '/admin/api/fournisseurs/'.$supplier->getId()->toRfc4122().'/decision', ['action' => 'approve']);

        self::assertSame(403, $this->httpStatus());
        self::assertSame(SupplierStatus::Pending, $this->em()->getRepository(SupplierProfile::class)->findOneBy([])->getStatus());
    }

    public function testRightsCanBeAddedOrRemovedForOneAdmin(): void
    {
        $supplier = $this->pendingSupplier();
        // « compta » n'approuve pas… sauf si on lui ajoute le droit.
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta', extra: [\App\Enum\Permission::SuppliersApprove]));
        $this->api('POST', '/admin/api/fournisseurs/'.$supplier->getId()->toRfc4122().'/decision', ['action' => 'approve']);
        self::assertSame(200, $this->httpStatus());

        // « moderation » approuve… sauf si on lui retire le droit (restriction).
        $this->newBrowser();
        $other = $this->pendingSupplier('autre-boutique@example.com', phone: '+261331112244');
        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation', denied: [\App\Enum\Permission::SuppliersApprove]));
        $this->api('POST', '/admin/api/fournisseurs/'.$other->getId()->toRfc4122().'/decision', ['action' => 'approve']);
        self::assertSame(403, $this->httpStatus());
    }

    public function testStateChangingCallsNeedTheCsrfToken(): void
    {
        $supplier = $this->pendingSupplier();
        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));

        $this->api('POST', '/admin/api/fournisseurs/'.$supplier->getId()->toRfc4122().'/decision', ['action' => 'approve'], withCsrf: false);

        self::assertSame(403, $this->httpStatus());
        self::assertSame(SupplierStatus::Pending, $this->em()->getRepository(SupplierProfile::class)->findOneBy([])->getStatus());
    }

    // ------------------------------------------------------------------ Suspension de comptes

    public function testSupportSuspendsAndReactivatesACustomer(): void
    {
        $customer = $this->createUser('client@example.com');
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));
        $uri = '/admin/api/utilisateurs/'.$customer->getId()->toRfc4122().'/statut';

        $r = $this->api('POST', $uri, ['action' => 'suspend']);
        self::assertSame('suspended', $r['status']);
        self::assertSame(AccountStatus::Suspended, $this->reload($customer)->getStatus());

        $r = $this->api('POST', $uri, ['action' => 'reactivate']);
        self::assertSame('active', $r['status']);
        self::assertSame(AccountStatus::Active, $this->reload($customer)->getStatus());
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.user.suspended']));
    }

    public function testSuspendedCustomerCanNoLongerSignIn(): void
    {
        $customer = $this->createUser('client@example.com');
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));
        $this->api('POST', '/admin/api/utilisateurs/'.$customer->getId()->toRfc4122().'/statut', ['action' => 'suspend']);
        $this->newBrowser();

        $r = $this->api('POST', '/auth/login', ['email' => 'client@example.com', 'password' => self::PASSWORD]);

        self::assertNotSame('done', $r['step'] ?? null);
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects();
    }

    public function testSuspensionCutsAnOpenSessionImmediately(): void
    {
        $customer = $this->createUser('client@example.com');
        $this->signIn($customer);
        $this->client->request('GET', '/compte');
        self::assertResponseIsSuccessful();

        // Un administrateur suspend le compte pendant que le client est connecté.
        $user = $this->reload($customer);
        $user->suspend();
        $this->em()->flush();

        $this->client->request('GET', '/compte');
        self::assertResponseRedirects();
    }

    public function testAnAdministratorCannotBeSuspendedFromTheUsersTab(): void
    {
        $target = $this->createAdminUser('cible@example.com', 'compta');
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));

        $r = $this->api('POST', '/admin/api/utilisateurs/'.$target->getId()->toRfc4122().'/statut', ['action' => 'suspend']);

        self::assertSame(403, $this->httpStatus());
        self::assertSame('use_admin_management', $r['error']);
        self::assertSame(AccountStatus::Active, $this->reload($target)->getStatus());
    }

    public function testAnAccountPendingDeletionKeepsItsStatus(): void
    {
        $customer = $this->reload($this->createUser('client@example.com'));
        $customer->requestDeletion(new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));

        $r = $this->api('POST', '/admin/api/utilisateurs/'.$customer->getId()->toRfc4122().'/statut', ['action' => 'suspend']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('invalid_transition', $r['error'], 'Suspendre un compte en cours de suppression empêcherait sa purge.');
    }

    // ------------------------------------------------------------------ Suivi de commande

    public function testSupportAdvancesTrackingAndAssignsACourier(): void
    {
        $number = $this->orderNumber();
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));

        $r = $this->api('POST', '/admin/api/commandes/'.$number.'/statut', ['status' => 'preparing', 'description' => 'Colis emballé', 'courierName' => 'Hery Rabe', 'courierPhone' => '034 12 345 67']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('preparing', $r['status']);
        $order = $this->em()->getRepository(Order::class)->findOneBy([]);
        self::assertSame(OrderStatus::Preparing, $order->getStatus());
        self::assertSame('Hery Rabe', $order->getCourierName());
        self::assertSame('+261341234567', $order->getCourierPhone(), 'Le téléphone du livreur est enregistré au format international.');
    }

    public function testTrackingCannotGoBackwards(): void
    {
        $number = $this->orderNumber();
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));
        $this->api('POST', '/admin/api/commandes/'.$number.'/statut', ['status' => 'out_for_delivery']);

        $r = $this->api('POST', '/admin/api/commandes/'.$number.'/statut', ['status' => 'preparing']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('order_backwards', $r['error']);
    }

    public function testFinanceAdminCannotUpdateTracking(): void
    {
        $number = $this->orderNumber();
        $this->signInAdmin($this->createAdminUser('fin@example.com', 'finance'));

        $this->api('POST', '/admin/api/commandes/'.$number.'/statut', ['status' => 'delivered']);

        self::assertSame(403, $this->httpStatus());
        self::assertSame(OrderStatus::Confirmed, $this->em()->getRepository(Order::class)->findOneBy([])->getStatus());
    }

    public function testUnknownOrderAndStatusAreRefused(): void
    {
        $number = $this->orderNumber();
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));

        $this->api('POST', '/admin/api/commandes/GB-2026-999999/statut', ['status' => 'preparing']);
        self::assertSame(404, $this->httpStatus());

        $r = $this->api('POST', '/admin/api/commandes/'.$number.'/statut', ['status' => 'teleported']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_action', $r['error']);
    }
}
