<?php

namespace App\Tests\Supplier;

use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\OrderStatus;
use App\Order\CatalogItem;
use App\Supplier\CommissionSchedule;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\InMemoryCatalog;

/**
 * Espace Fournisseur : chiffres RÉELS (dossier, commandes reçues, ventes), jamais ceux d'un autre fournisseur,
 * et aucune donnée personnelle de client.
 */
final class SupplierDashboardTest extends AppTestCase
{
    private function supplier(string $email, string $company, ?array $application = null, string $phone = '+261331112233'): User
    {
        $user = $this->createUser($email, AccountType::Supplier, true, $phone, true);
        $this->em()->persist(new SupplierProfile($this->reload($user), $company, 'Lot 12 Analakely', 'Vêtements', new \DateTimeImmutable(), $application));
        $this->em()->flush();
        $this->em()->clear();

        return $user;
    }

    /** Approuve le dossier du fournisseur (seul un fournisseur approuvé voit ses ventes). */
    private function approve(User $supplier): void
    {
        $admin = $this->reload($this->createAdminUser('modo-'.bin2hex(random_bytes(3)).'@example.com', 'moderation'));
        $profile = $this->em()->getRepository(SupplierProfile::class)->find($supplier->getId());
        $profile->approve($admin, new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();
    }

    /** Un client passe une commande ; le navigateur est ensuite « vidé ». @return string numéro de commande */
    private function placeOrder(string $ref, int $quantity, string $key): string
    {
        // Un numéro vérifié est unique en base : chaque acheteur a le sien.
        $client = $this->createUser('acheteur-'.$key.'@example.com', phone: '+2613412345'.(60 + \ord($key) % 40), phoneVerified: true);
        $this->signIn($client);
        $r = $this->api('POST', '/commande/valider', [
            'items' => [['ref' => $ref, 'quantity' => $quantity]],
            'address' => ['fullName' => 'Rakoto Secret', 'phone' => '', 'street' => 'Rue Confidentielle 9', 'city' => 'Antananarivo', 'postalCode' => '101', 'courierNote' => ''],
            'payment' => ['method' => 'cash_on_delivery'],
            'idempotencyKey' => 'supplier-test-'.$key,
        ]);
        self::assertContains($this->httpStatus(), [200, 201]);
        $this->newBrowser();

        return $r['order']['orderNumber'];
    }

    private function dashboard(string $locale = 'fr'): string
    {
        $this->client->request('GET', '/fournisseur', server: ['HTTP_ACCEPT_LANGUAGE' => $locale]);
        $this->em()->clear();
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    public function testShowsTheRealShopNameAndPendingStatus(): void
    {
        $this->signIn($this->supplier('marie@boutique.mg', 'Boutique Marie'));

        $html = $this->dashboard();
        self::assertStringContainsString('Bonjour, Boutique Marie', $html);
        self::assertStringNotContainsString('[Votre boutique]', $html);
        self::assertStringContainsString('data-supplier-status="pending"', $html);
        self::assertStringContainsString('Dossier en cours d&#039;examen', $html);
        self::assertStringContainsString('Lot 12 Analakely', $html);
        self::assertStringContainsString('033 11 122 33', $html);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testRejectedSupplierSeesTheReason(): void
    {
        $user = $this->supplier('marie@boutique.mg', 'Boutique Marie');
        $admin = $this->reload($this->createAdminUser('modo@example.com', 'moderation'));
        // On recharge le dossier APRÈS l'administrateur : reload() vide l'EntityManager et détacherait le dossier sinon.
        $profile = $this->em()->getRepository(SupplierProfile::class)->find($user->getId());
        $profile->reject($admin, 'Pièce d\'identité illisible', new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();
        $this->signIn($user);

        $html = $this->dashboard();
        self::assertStringContainsString('data-supplier-status="rejected"', $html);
        self::assertStringContainsString('Pièce d&#039;identité illisible', $html);
    }

    public function testOrdersAndSalesAreCountedForThisSupplierOnly(): void
    {
        $mine = $this->supplier('marie@boutique.mg', 'Boutique Marie');
        $other = $this->supplier('paul@boutique.mg', 'Boutique Paul', null, '+261341112244');
        $catalog = static::getContainer()->get(InMemoryCatalog::class);
        $catalog->add(new CatalogItem('p-mine', 'Robe wax', 50000, $mine->getId()));
        $catalog->add(new CatalogItem('p-other', 'Chaussures cuir', 90000, $other->getId()));

        $number = $this->placeOrder('p-mine', 2, 'a');
        $this->placeOrder('p-other', 1, 'b');
        $this->approve($mine);

        $this->signIn($mine);
        $html = $this->dashboard();

        // 2 × 50 000 = 100 000 Ar pour Marie ; rien de la commande de Paul.
        self::assertStringContainsString($number, $html);
        self::assertStringContainsString('2 × Robe wax', $html);
        self::assertStringContainsString('100 000 Ar', preg_replace('/[\x{202F}\x{00A0}]/u', ' ', $html));
        self::assertStringNotContainsString('Chaussures cuir', $html);
        self::assertStringNotContainsString('90 000', preg_replace('/[\x{202F}\x{00A0}]/u', ' ', $html));
        // Aucune donnée personnelle du client.
        self::assertStringNotContainsString('Rakoto Secret', $html);
        self::assertStringNotContainsString('Rue Confidentielle', $html);
        self::assertStringNotContainsString('acheteur-a@example.com', $html);
        // Le graphique n'est plus l'écran vide : une colonne a une hauteur non nulle.
        self::assertStringContainsString('data-chart-series="sales"', $html);
        self::assertStringNotContainsString('Aucune donnée de vente pour le moment', $html);
    }

    public function testSalesFiguresAreHiddenFromSuppliersWhoAreNotApproved(): void
    {
        $mine = $this->supplier('marie@boutique.mg', 'Boutique Marie');
        static::getContainer()->get(InMemoryCatalog::class)->add(new CatalogItem('p-mine', 'Robe wax', 50000, $mine->getId()));
        $number = $this->placeOrder('p-mine', 2, 'a');

        // En attente, puis suspendu : l'espace s'ouvre (statut visible) mais ni commandes, ni montants.
        $this->signIn($mine);
        $html = $this->dashboard();
        self::assertStringNotContainsString($number, $html);
        self::assertStringNotContainsString('Robe wax', $html);
        self::assertStringNotContainsString('100 000', preg_replace('/[\x{202F}\x{00A0}]/u', ' ', $html));

        $this->newBrowser();
        $this->approve($mine);
        $admin = $this->reload($this->createAdminUser('modo2@example.com', 'moderation'));
        $profile = $this->em()->getRepository(SupplierProfile::class)->find($mine->getId());
        $profile->suspend($admin, 'Litige', new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();

        $this->signIn($mine);
        $html = $this->dashboard();
        self::assertStringContainsString('data-supplier-status="suspended"', $html);
        self::assertStringNotContainsString($number, $html);
        self::assertStringNotContainsString('100 000', preg_replace('/[\x{202F}\x{00A0}]/u', ' ', $html));
    }

    public function testCancelledOrdersAreNotCountedAsSales(): void
    {
        $mine = $this->supplier('marie@boutique.mg', 'Boutique Marie');
        static::getContainer()->get(InMemoryCatalog::class)->add(new CatalogItem('p-mine', 'Robe wax', 50000, $mine->getId()));
        $number = $this->placeOrder('p-mine', 1, 'a');

        $order = $this->em()->getRepository(\App\Entity\Order::class)->findOneBy(['orderNumber' => $number]);
        $order->advanceTo(OrderStatus::Cancelled, null, null, new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();

        $this->signIn($mine);
        $stats = static::getContainer()->get(\App\Repository\OrderItemRepository::class)->statsForSupplier($mine->getId()->toRfc4122());
        self::assertSame(['orders' => 0, 'preparing' => 0, 'delivered' => 0, 'salesTotal' => 0], $stats);
    }

    public function testNoFakeControlsRemain(): void
    {
        $this->signIn($this->supplier('marie@boutique.mg', 'Boutique Marie'));
        $html = $this->dashboard();

        // Plus de faux changement de formule ni de faux enregistrement de RIB : ils n'écrivaient rien en base.
        foreach (['data-plan-toggle', 'data-save-payout', 'data-modal="add-product"'] as $fake) {
            self::assertStringNotContainsString($fake, $html, $fake);
        }
        // Le bouton « Ajouter un produit » est désactivé tant que le catalogue n'existe pas.
        self::assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>.*?Ajouter un produit/s', $html);
    }

    public function testExpectedCommissionRateFollowsTheApplication(): void
    {
        $schedule = new CommissionSchedule();
        self::assertSame(8, $schedule->rateFor('cat-mode', 'standard'));
        self::assertSame(6, $schedule->rateFor('cat-mode', 'pro'));
        self::assertSame(8, $schedule->rateFor('cat-mode', 'gratuit'), 'Une formule inconnue ne donne jamais un taux réduit.');
        self::assertNull($schedule->rateFor('cat-inconnu', 'pro'));
        self::assertNull($schedule->rateFor(null, 'standard'));

        $this->signIn($this->supplier('marie@boutique.mg', 'Boutique Marie', ['mainCategory' => 'cat-elec', 'plan' => 'pro']));
        $html = $this->dashboard();
        self::assertMatchesRegularExpression('/data-commission-rate[^>]*>\s*3%/', $html);
        self::assertStringContainsString('Boutique Pro Certifiée', $html);
    }

    public function testDashboardIsTranslated(): void
    {
        $this->signIn($this->supplier('marie@boutique.mg', 'Boutique Marie'));
        foreach (['en' => 'Hello, Boutique Marie', 'es' => 'Hola, Boutique Marie'] as $locale => $hello) {
            // À la connexion, la langue enregistrée du compte passe en session : on change de langue comme le fait le sélecteur du pied de page.
            $this->client->request('GET', '/langue/'.$locale, server: ['HTTP_REFERER' => 'http://localhost/']);
            $html = $this->dashboard($locale);
            self::assertStringContainsString($hello, $html, $locale);
            self::assertStringNotContainsString('Espace Fournisseur', $html, $locale);
            self::assertDoesNotMatchRegularExpression('/(?<![\w\/.#-])supplier\.[a-z_]+\.[a-z_.]+/', preg_replace('#<(script|style)\b.*?</\1>#si', '', $html), $locale.' : clé brute visible');
        }
    }

    public function testClientCannotOpenTheSupplierArea(): void
    {
        $this->signIn($this->createUser('client@example.com'));
        $this->client->request('GET', '/fournisseur');
        self::assertResponseStatusCodeSame(403);
    }
}
