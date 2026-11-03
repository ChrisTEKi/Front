<?php

namespace App\Tests\Catalog;

use App\Catalog\DoctrineCatalog;
use App\Catalog\ProductData;
use App\Entity\Product;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\Currency;
use App\Enum\Permission;
use App\Enum\ProductStatus;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Clock\Clock;

/**
 * Catalogue de produits : gestion par le fournisseur, modération par les administrateurs, boutique et commandes.
 */
final class ProductCatalogTest extends AppTestCase
{
    private const PRODUCT = ['name' => 'Casque audio', 'description' => 'Sans fil', 'category' => 'electronique', 'reference' => 'CA-01', 'priceMga' => '120000', 'prices' => ['EUR' => '25,50', 'USD' => '']];

    // ------------------------------------------------------------------ Aides

    /** Fournisseur avec son dossier ; $status : pending | approved | rejected | suspended. */
    private function supplier(string $email = 'boutique@example.com', string $status = 'approved', string $phone = '+261331112233', string $company = 'Ma Boutique'): User
    {
        $user = $this->createUser($email, AccountType::Supplier, true, $phone, true);
        // L'administrateur est créé AVANT de charger le dossier : createAdminUser vide l'EntityManager.
        $reviewer = 'pending' === $status ? null : $this->createAdminUser('modo-'.bin2hex(random_bytes(3)).'@example.com', 'moderation');
        $this->em()->clear();

        $profile = new SupplierProfile($this->em()->find(User::class, $user->getId()), $company, 'Lot 12 Analakely', 'Vêtements', new \DateTimeImmutable());
        $this->em()->persist($profile);
        if (null !== $reviewer) {
            $admin = $this->em()->find(User::class, $reviewer->getId());
            $now = new \DateTimeImmutable();
            match ($status) {
                'approved' => $profile->approve($admin, $now),
                'rejected' => $profile->reject($admin, 'Dossier incomplet', $now),
                'suspended' => $profile->suspend($admin, 'Plaintes clients', $now),
            };
        }
        $this->em()->flush();
        $this->em()->clear();

        return $user;
    }

    /** Crée un produit directement en base. */
    private function product(?User $owner, string $name = 'Produit', int $price = 1000, string $status = 'active'): Product
    {
        $product = new Product(null === $owner ? null : $this->em()->getReference(User::class, $owner->getId()), $name, $price, new \DateTimeImmutable());
        if ('active' !== $status) {
            $product->setVisible(false, new \DateTimeImmutable());
        }
        $this->em()->persist($product);
        $this->em()->flush();
        $this->em()->clear();

        return $product;
    }

    private function fresh(Product $product): Product
    {
        $this->em()->clear();

        return $this->em()->getRepository(Product::class)->find($product->getId());
    }

    private function id(Product $p): string
    {
        return $p->getId()->toRfc4122();
    }

    /** Administrateur « support » à qui l'on ajoute des droits (clés comme « products.remove »). */
    private function adminWith(array $permissions, string $email = 'gestion@example.com'): User
    {
        return $this->createAdminUser($email, 'support', array_map(static fn (string $key): Permission => Permission::from($key), $permissions));
    }

    private function page(string $url): string
    {
        $this->client->request('GET', $url);

        return (string) $this->client->getResponse()->getContent();
    }

    // ------------------------------------------------------------------ Lecture des montants

    public function testPricesAreParsedIntoWholeUnits(): void
    {
        self::assertSame(12500, ProductData::parseMinor('12500', Currency::MGA));
        self::assertSame(12500, ProductData::parseMinor('12 500', Currency::MGA));
        self::assertSame(1250, ProductData::parseMinor('12,50', Currency::EUR));
        self::assertSame(1250, ProductData::parseMinor('12.5', Currency::EUR));
        self::assertSame(1200, ProductData::parseMinor('12', Currency::USD));
        self::assertNull(ProductData::parseMinor('12.505', Currency::EUR), 'Pas de fraction de centime.');
        self::assertNull(ProductData::parseMinor('12500.5', Currency::MGA), 'L\'Ariary n\'a pas de décimale.');
        self::assertNull(ProductData::parseMinor('-5', Currency::MGA));
        self::assertNull(ProductData::parseMinor('abc', Currency::MGA));
        self::assertNull(ProductData::parseMinor('100000001', Currency::MGA), 'Au-delà du maximum.');
    }

    // ------------------------------------------------------------------ Espace fournisseur

    public function testSupplierPagesAreReservedToSuppliers(): void
    {
        $this->signIn($this->createUser('client@example.com'));
        $this->client->request('GET', '/fournisseur/produits');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);

        $this->api('POST', '/fournisseur/api/produits', self::PRODUCT);
        self::assertContains($this->httpStatus(), [302, 401, 403]);
        self::assertSame(0, $this->em()->getRepository(Product::class)->count([]));
    }

    public function testSupplierSeesHisEmptyCatalogue(): void
    {
        $this->signIn($this->supplier());

        $html = $this->page('/fournisseur/produits');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-supplier-products', $html);
        self::assertStringContainsString('Aucun produit dans votre catalogue pour le moment.', $html);
    }

    public function testSupplierCreatesAProductWithPricesPerCurrency(): void
    {
        $supplier = $this->supplier();
        $this->signIn($supplier);

        $r = $this->api('POST', '/fournisseur/api/produits', self::PRODUCT);

        self::assertSame(201, $this->httpStatus());
        self::assertCount(1, $r['products']);
        $this->em()->clear();
        $product = $this->em()->getRepository(Product::class)->findOneBy([]);
        self::assertSame('Casque audio', $product->getName());
        self::assertSame(120000, $product->getPriceMga());
        self::assertSame(['EUR' => 2550], $product->getPriceOverrides(), 'USD vide = pas de prix spécifique.');
        self::assertSame(ProductStatus::Active, $product->getStatus());
        self::assertTrue($product->getOwner()->getId()->equals($supplier->getId()));
        self::assertSame('120000', $r['products'][0]['priceMga'] ? (string) $r['products'][0]['priceMga'] : '');
        self::assertSame(['EUR' => '25.50'], $r['products'][0]['prices']);
    }

    public function testInvalidProductIsRefusedFieldByField(): void
    {
        $this->signIn($this->supplier());

        $r = $this->api('POST', '/fournisseur/api/produits', ['name' => '', 'priceMga' => '12,5', 'prices' => ['EUR' => 'abc', 'GBP' => '5']]);

        self::assertSame(422, $this->httpStatus());
        self::assertEqualsCanonicalizing(['name', 'priceMga', 'price_EUR', 'price_GBP'], array_keys($r['errors']));
        self::assertSame(0, $this->em()->getRepository(Product::class)->count([]));
    }

    public function testZeroOrMissingPriceIsRefused(): void
    {
        $this->signIn($this->supplier());

        $r = $this->api('POST', '/fournisseur/api/produits', ['name' => 'Gratuit', 'priceMga' => '0']);
        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('priceMga', $r['errors']);

        $r = $this->api('POST', '/fournisseur/api/produits', ['name' => 'Sans prix']);
        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('priceMga', $r['errors']);
    }

    public function testSupplierUpdatesHidesShowsAndDeletesHisProduct(): void
    {
        $supplier = $this->supplier();
        $product = $this->product($supplier, 'Ancien nom', 5000);
        $this->signIn($supplier);

        $this->api('POST', '/fournisseur/api/produits/'.$this->id($product).'/modifier', ['name' => 'Nouveau nom', 'priceMga' => '7000', 'prices' => ['USD' => '2']]);
        self::assertSame(200, $this->httpStatus());
        $p = $this->fresh($product);
        self::assertSame('Nouveau nom', $p->getName());
        self::assertSame(7000, $p->getPriceMga());
        self::assertSame(['USD' => 200], $p->getPriceOverrides());

        $this->api('POST', '/fournisseur/api/produits/'.$this->id($product).'/visibilite', ['visible' => false]);
        self::assertSame(ProductStatus::Hidden, $this->fresh($product)->getStatus());
        $this->api('POST', '/fournisseur/api/produits/'.$this->id($product).'/visibilite', ['visible' => true]);
        self::assertSame(ProductStatus::Active, $this->fresh($product)->getStatus());

        $this->api('POST', '/fournisseur/api/produits/'.$this->id($product).'/supprimer');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, $this->em()->getRepository(Product::class)->count([]));
    }

    public function testASupplierCannotTouchSomeoneElsesProduct(): void
    {
        $other = $this->supplier('autre@example.com', 'approved', '+261332223344', 'Autre Boutique');
        $product = $this->product($other, 'Secret', 9000);
        $this->signIn($this->supplier());

        foreach (['modifier' => self::PRODUCT, 'visibilite' => ['visible' => false], 'supprimer' => []] as $action => $payload) {
            $this->api('POST', '/fournisseur/api/produits/'.$this->id($product).'/'.$action, $payload);
            self::assertSame(404, $this->httpStatus(), $action);
        }
        $p = $this->fresh($product);
        self::assertSame('Secret', $p->getName());
        self::assertSame(ProductStatus::Active, $p->getStatus());
    }

    public function testRejectedAndSuspendedSuppliersCannotManageProducts(): void
    {
        foreach (['rejected' => '+261331110001', 'suspended' => '+261331110002'] as $status => $phone) {
            $this->newBrowser();
            $this->signIn($this->supplier($status.'@example.com', $status, $phone));

            $r = $this->api('POST', '/fournisseur/api/produits', self::PRODUCT);

            self::assertSame(403, $this->httpStatus(), $status);
            self::assertSame('supplier_not_active', $r['error']);
        }
        self::assertSame(0, $this->em()->getRepository(Product::class)->count([]));
    }

    public function testASupplierWhoseApplicationIsPendingCanPrepareProductsButTheyAreNotOnSaleYet(): void
    {
        $supplier = $this->supplier('attente@example.com', 'pending');
        $this->signIn($supplier);

        $this->api('POST', '/fournisseur/api/produits', self::PRODUCT);
        self::assertSame(201, $this->httpStatus());
        $product = $this->em()->getRepository(Product::class)->findOneBy([]);

        self::assertFalse(static::getContainer()->get(DoctrineCatalog::class)->find($this->id($product))->available, 'Pas vendable avant approbation.');
        $this->newBrowser();
        $this->client->request('GET', '/produit/'.$this->id($product));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheCatalogueIsLimitedPerSupplier(): void
    {
        $supplier = $this->supplier();
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO products (id, owner_id, name, price_mga, price_overrides, status, created_at, updated_at) SELECT gen_random_uuid(), :owner, 'P'||g, 1000, '{}', 'hidden', now(), now() FROM generate_series(1, 2000) g",
            ['owner' => $supplier->getId()->toRfc4122()],
        );
        $this->signIn($supplier);

        $r = $this->api('POST', '/fournisseur/api/produits', self::PRODUCT);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('product_limit', $r['error']);
    }

    // ------------------------------------------------------------------ Le catalogue branché sur les commandes

    public function testDoctrineCatalogReadsServerSidePriceAndAvailability(): void
    {
        $supplier = $this->supplier();
        $sellable = $this->product($supplier, 'Casque', 120000);
        $hidden = $this->product($supplier, 'Caché', 5000, 'hidden');
        $gobuy = $this->product(null, 'Produit GoBuy', 800);
        $catalog = static::getContainer()->get(DoctrineCatalog::class);

        $item = $catalog->find($this->id($sellable));
        self::assertSame(120000, $item->unitPrice);
        self::assertSame('Casque', $item->name);
        self::assertTrue($item->available);
        self::assertTrue($item->supplierId->equals($supplier->getId()));

        self::assertFalse($catalog->find($this->id($hidden))->available, 'Masqué : indisponible.');
        $g = $catalog->find($this->id($gobuy));
        self::assertTrue($g->available);
        self::assertNull($g->supplierId, 'Produit GoBuy : pas de fournisseur.');

        self::assertNull($catalog->find('pas-un-uuid'));
        self::assertNull($catalog->find('01920000-0000-7000-8000-00000000ffff'));
    }

    public function testAProductIsUnavailableWhenItsSupplierIsNoLongerApproved(): void
    {
        $supplier = $this->supplier();
        $product = $this->product($supplier, 'Casque', 120000);
        $catalog = static::getContainer()->get(DoctrineCatalog::class);
        self::assertTrue($catalog->find($this->id($product))->available);

        $this->em()->getConnection()->executeStatement("UPDATE supplier_profiles SET status = 'suspended'");
        self::assertFalse($catalog->find($this->id($product))->available);

        $this->em()->getConnection()->executeStatement("UPDATE supplier_profiles SET status = 'approved'");
        $this->em()->getConnection()->executeStatement("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => $supplier->getId()->toRfc4122()]);
        self::assertFalse($catalog->find($this->id($product))->available, 'Compte suspendu : plus vendable.');
    }

    // ------------------------------------------------------------------ Boutique

    public function testStorefrontShowsRealProductsFeaturedFirst(): void
    {
        $supplier = $this->supplier();
        $old = $this->product($supplier, 'Premier produit', 1000);
        $this->product($supplier, 'Dernier produit', 2000);
        $this->product($supplier, 'Produit masqué', 3000, 'hidden');
        $this->product(null, 'Article GoBuy', 4000);
        $adminUser = $this->adminWith(['products.feature']);
        $featured = $this->fresh($old);
        $featured->feature($this->em()->find(User::class, $adminUser->getId()), null, new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();

        $html = $this->page('/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Dernier produit', $html);
        self::assertStringContainsString('Article GoBuy', $html);
        self::assertStringNotContainsString('Produit masqué', $html);
        self::assertLessThan(strpos($html, 'Dernier produit'), strpos($html, 'Premier produit'), 'Le produit mis en avant passe en premier.');
        self::assertStringContainsString('Ma Boutique', $html, 'Le nom de la société du vendeur est affiché.');
    }

    public function testProductPageShowsSellableProductsAndHidesOthers(): void
    {
        $supplier = $this->supplier();
        $good = $this->product($supplier, 'Casque audio', 120000);
        $hidden = $this->product($supplier, 'Caché', 5000, 'hidden');

        $html = $this->page('/produit/'.$this->id($good));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Casque audio', $html);

        $this->client->request('GET', '/produit/'.$this->id($hidden));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/produit/pas-un-uuid');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ------------------------------------------------------------------ Administration : accès

    public function testAdminProductsPageNeedsTheRight(): void
    {
        $this->product($this->supplier(), 'Casque', 1000);

        $this->signIn($this->createUser('client@example.com'));
        $this->client->request('GET', '/admin/produits');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);

        $this->newBrowser();
        $this->signInAdmin($this->createAdminUser('fin@example.com', 'finance'));
        $this->client->request('GET', '/admin/produits');
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Un admin sans le droit products.view ne voit rien.');
    }

    public function testAdminSeesEveryoneProductsAndCanFilter(): void
    {
        $a = $this->supplier('a@example.com', 'approved', '+261331110011', 'Boutique A');
        $b = $this->supplier('b@example.com', 'approved', '+261331110012', 'Boutique B');
        $this->product($a, 'Chaise', 1000);
        $this->product($b, 'Table', 2000, 'hidden');
        $this->product(null, 'Lampe GoBuy', 3000);
        $this->signInAdmin($this->adminWith(['products.view']));

        $html = $this->page('/admin/produits');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        foreach (['Chaise', 'Table', 'Lampe GoBuy', 'Boutique A', 'Boutique B'] as $text) {
            self::assertStringContainsString($text, $html);
        }

        $html = $this->page('/admin/produits?owner=gobuy');
        self::assertStringContainsString('Lampe GoBuy', $html);
        self::assertStringNotContainsString('Chaise', $html);

        $html = $this->page('/admin/produits?status=hidden');
        self::assertStringContainsString('Table', $html);
        self::assertStringNotContainsString('Chaise', $html);

        $html = $this->page('/admin/produits?q=boutique+a');
        self::assertStringContainsString('Chaise', $html);
        self::assertStringNotContainsString('Table', $html);

        $html = $this->page('/admin/produits?owner='.$a->getId()->toRfc4122());
        self::assertStringContainsString('Chaise', $html);
        self::assertStringNotContainsString('Lampe GoBuy', $html);

        $this->client->request('GET', '/admin/produits?owner=%27%3B--&status=zzz&page=-4');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'Des filtres invalides ne cassent pas la page.');
    }

    public function testAdminActionsNeedTheirOwnRight(): void
    {
        $product = $this->product($this->supplier(), 'Casque', 1000);
        $this->signInAdmin($this->adminWith(['products.view']));
        $url = '/admin/api/produits/'.$this->id($product);

        $this->api('POST', $url.'/retrait', ['action' => 'remove', 'reason' => 'Contrefaçon']);
        self::assertSame(403, $this->httpStatus());
        $this->api('POST', $url.'/signalement', ['action' => 'flag', 'reason' => 'Douteux']);
        self::assertSame(403, $this->httpStatus());
        $this->api('POST', $url.'/mise-en-avant', ['action' => 'feature']);
        self::assertSame(403, $this->httpStatus());
        $this->api('POST', $url.'/supprimer', ['password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());
        $this->api('POST', '/admin/api/produits', self::PRODUCT);
        self::assertSame(403, $this->httpStatus());

        $p = $this->fresh($product);
        self::assertSame(ProductStatus::Active, $p->getStatus());
        self::assertFalse($p->isFlagged());
    }

    // ------------------------------------------------------------------ Administration : modération

    public function testRemovingAProductNeedsAReasonNotifiesTheSupplierAndLocksIt(): void
    {
        $supplier = $this->supplier();
        $product = $this->product($supplier, 'Contrefaçon', 1000);
        $this->signInAdmin($this->adminWith(['products.view', 'products.remove']));
        $url = '/admin/api/produits/'.$this->id($product);

        $r = $this->api('POST', $url.'/retrait', ['action' => 'remove', 'reason' => 'ok']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('reason_required', $r['error']);
        self::assertSame(ProductStatus::Active, $this->fresh($product)->getStatus());

        $this->api('POST', $url.'/retrait', ['action' => 'remove', 'reason' => 'Produit contrefait signalé par un client']);
        self::assertSame(200, $this->httpStatus());
        $p = $this->fresh($product);
        self::assertSame(ProductStatus::Removed, $p->getStatus());
        self::assertSame('Produit contrefait signalé par un client', $p->getRemovedReason());
        self::assertNotNull($p->getRemovedAt());
        self::assertEmailCount(1);
        $mail = self::getMailerMessage(0);
        self::assertEmailHeaderSame($mail, 'To', 'Test Fournisseur <boutique@example.com>');
        self::assertEmailTextBodyContains($mail, 'Produit contrefait signalé par un client');

        // Déjà retiré : on ne le retire pas deux fois.
        $r = $this->api('POST', $url.'/retrait', ['action' => 'remove', 'reason' => 'Encore une fois']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('already_removed', $r['error']);

        // Le produit n'est plus en boutique, et le fournisseur ne peut plus le toucher.
        $this->newBrowser();
        $this->client->request('GET', '/produit/'.$this->id($product));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->signIn($supplier);
        foreach (['modifier' => self::PRODUCT, 'visibilite' => ['visible' => true], 'supprimer' => []] as $action => $payload) {
            $r = $this->api('POST', '/fournisseur/api/produits/'.$this->id($product).'/'.$action, $payload);
            self::assertSame(409, $this->httpStatus(), $action);
            self::assertSame('product_removed', $r['error'], $action);
        }
        self::assertSame(ProductStatus::Removed, $this->fresh($product)->getStatus());
        self::assertStringContainsString('Produit contrefait signalé', $this->page('/fournisseur/produits'));
    }

    public function testRestoringBringsTheProductBack(): void
    {
        $supplier = $this->supplier();
        $product = $this->product($supplier, 'Casque', 1000);
        $this->signInAdmin($this->adminWith(['products.remove']));
        $url = '/admin/api/produits/'.$this->id($product).'/retrait';

        $r = $this->api('POST', $url, ['action' => 'restore']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('not_removed', $r['error']);

        $this->api('POST', $url, ['action' => 'remove', 'reason' => 'Vérification en cours']);
        $this->api('POST', $url, ['action' => 'restore']);
        self::assertSame(200, $this->httpStatus());
        $p = $this->fresh($product);
        self::assertSame(ProductStatus::Active, $p->getStatus());
        self::assertNull($p->getRemovedReason());
        self::assertTrue(static::getContainer()->get(DoctrineCatalog::class)->find($this->id($product))->available);
    }

    public function testFlaggingIsAMarkWithoutEffectOnSales(): void
    {
        $product = $this->product($this->supplier(), 'Casque', 1000);
        $this->signInAdmin($this->adminWith(['products.flag', 'products.view']));
        $url = '/admin/api/produits/'.$this->id($product).'/signalement';

        $r = $this->api('POST', $url, ['action' => 'flag', 'reason' => '']);
        self::assertSame('reason_required', $r['error']);

        $this->api('POST', $url, ['action' => 'flag', 'reason' => 'Photo non conforme']);
        $p = $this->fresh($product);
        self::assertTrue($p->isFlagged());
        self::assertSame('Photo non conforme', $p->getFlagReason());
        self::assertTrue(static::getContainer()->get(DoctrineCatalog::class)->find($this->id($product))->available);
        self::assertStringContainsString('Photo non conforme', $this->page('/admin/produits?flagged=1'));

        $this->api('POST', $url, ['action' => 'unflag']);
        self::assertFalse($this->fresh($product)->isFlagged());
        self::assertStringNotContainsString('Photo non conforme', $this->page('/admin/produits?flagged=1'));
    }

    public function testFeaturingHasADurationAndOnlyAppliesToProductsOnSale(): void
    {
        $supplier = $this->supplier();
        $good = $this->product($supplier, 'Casque', 1000);
        $hidden = $this->product($supplier, 'Caché', 1000, 'hidden');
        $this->signInAdmin($this->adminWith(['products.feature']));
        $this->api('POST', '/admin/api/produits/'.$this->id($good).'/mise-en-avant', ['action' => 'feature', 'days' => 30]);
        $now = Clock::get()->now(); // l'horloge de test a été avancée par la connexion
        self::assertSame(200, $this->httpStatus());
        $p = $this->fresh($good);
        self::assertTrue($p->isFeatured($now));
        self::assertEqualsWithDelta($now->modify('+30 days')->getTimestamp(), $p->getFeaturedUntil()->getTimestamp(), 5);
        self::assertFalse($p->isFeatured($now->modify('+31 days')), 'La mise en avant expire d\'elle-même.');

        $this->api('POST', '/admin/api/produits/'.$this->id($good).'/mise-en-avant', ['action' => 'feature', 'days' => '']);
        self::assertNull($this->fresh($good)->getFeaturedUntil(), 'Sans durée : jusqu\'à décision contraire.');

        foreach ([0, 366, 'abc', -3] as $days) {
            $r = $this->api('POST', '/admin/api/produits/'.$this->id($good).'/mise-en-avant', ['action' => 'feature', 'days' => $days]);
            self::assertSame(422, $this->httpStatus(), (string) $days);
            self::assertSame('invalid_days', $r['error']);
        }

        $r = $this->api('POST', '/admin/api/produits/'.$this->id($hidden).'/mise-en-avant', ['action' => 'feature']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('product_not_active', $r['error']);

        $this->api('POST', '/admin/api/produits/'.$this->id($good).'/mise-en-avant', ['action' => 'unfeature']);
        self::assertFalse($this->fresh($good)->isFeatured($now));
    }

    public function testDeletingAProductNeedsThePasswordAndIsDefinitive(): void
    {
        $supplier = $this->supplier();
        $product = $this->product($supplier, 'Casque', 1000);
        $this->signInAdmin($this->adminWith(['products.delete']));
        $url = '/admin/api/produits/'.$this->id($product).'/supprimer';

        $r = $this->api('POST', $url, ['password' => 'mauvais-mot-de-passe']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('step_up_failed', $r['error']);
        self::assertSame(1, $this->em()->getRepository(Product::class)->count([]));

        $this->api('POST', $url, ['password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, $this->em()->getRepository(Product::class)->count([]));
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'To', 'Test Fournisseur <boutique@example.com>');

        $this->api('POST', $url, ['password' => self::PASSWORD]);
        self::assertSame(404, $this->httpStatus());
    }

    // ------------------------------------------------------------------ Produits de GoBuy

    public function testAdminManagesGobuyOwnProductsButNeverRewritesASuppliersProduct(): void
    {
        $supplier = $this->supplier();
        $theirs = $this->product($supplier, 'Casque', 1000);
        $this->signInAdmin($this->adminWith(['products.manage', 'products.view']));

        $this->api('POST', '/admin/api/produits', self::PRODUCT);
        self::assertSame(201, $this->httpStatus());
        $mine = $this->em()->getRepository(Product::class)->findOneBy(['name' => 'Casque audio']);
        self::assertNull($mine->getOwner(), 'Produit GoBuy : pas de propriétaire.');

        $this->api('POST', '/admin/api/produits/'.$this->id($mine).'/modifier', ['name' => 'Casque GoBuy', 'priceMga' => '99000']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('Casque GoBuy', $this->fresh($mine)->getName());
        self::assertSame(99000, $this->fresh($mine)->getPriceMga());

        $r = $this->api('POST', '/admin/api/produits/'.$this->id($theirs).'/modifier', ['name' => 'Piraté', 'priceMga' => '1']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('gobuy_only', $r['error']);
        self::assertSame('Casque', $this->fresh($theirs)->getName());

        $r = $this->api('POST', '/admin/api/produits', ['name' => '', 'priceMga' => 'x']);
        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('priceMga', $r['errors']);
    }

    // ------------------------------------------------------------------ Catalogue entier d'un fournisseur

    public function testWholeCatalogueCanBeRemovedOrDeletedWithTheRightRightsAndPassword(): void
    {
        $a = $this->supplier('a@example.com', 'approved', '+261331110021', 'Boutique A');
        $b = $this->supplier('b@example.com', 'approved', '+261331110022', 'Boutique B');
        foreach (['A1', 'A2', 'A3'] as $name) {
            $this->product($a, $name);
        }
        $this->product($a, 'A4 caché', 1000, 'hidden');
        $keep = $this->product($b, 'B1');
        $this->signInAdmin($this->adminWith(['products.remove'])); // retirer oui, supprimer non
        $url = '/admin/api/fournisseurs/'.$a->getId()->toRfc4122().'/catalogue';

        $r = $this->api('POST', $url, ['action' => 'remove', 'reason' => 'Compte frauduleux', 'password' => 'faux']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('step_up_failed', $r['error']);

        $r = $this->api('POST', $url, ['action' => 'remove', 'reason' => 'Compte frauduleux', 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(4, $r['count']);
        $this->em()->clear();
        $products = $this->em()->getRepository(Product::class);
        self::assertSame(4, $products->count(['status' => ProductStatus::Removed]));
        self::assertSame('Compte frauduleux', $products->findOneBy(['name' => 'A1'])->getRemovedReason());
        self::assertSame(ProductStatus::Active, $this->fresh($keep)->getStatus(), 'Le catalogue d\'un autre vendeur n\'est pas touché.');
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'To', 'Test Fournisseur <a@example.com>');

        // Supprimer exige le droit « products.delete » : refusé ici.
        $this->api('POST', $url, ['action' => 'delete', 'password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());
        self::assertSame(4, $this->em()->getRepository(Product::class)->count(['owner' => $a->getId()]));
    }

    public function testDeletingAWholeCatalogueIsDefinitiveAndLimitedToThatSupplier(): void
    {
        $a = $this->supplier('a@example.com', 'approved', '+261331110021', 'Boutique A');
        $b = $this->supplier('b@example.com', 'approved', '+261331110022', 'Boutique B');
        $this->product($a, 'A1');
        $this->product($a, 'A2');
        $this->product($b, 'B1');
        $this->product(null, 'GoBuy 1');
        $this->signInAdmin($this->adminWith(['products.delete']));

        $r = $this->api('POST', '/admin/api/fournisseurs/'.$a->getId()->toRfc4122().'/catalogue', ['action' => 'delete', 'password' => self::PASSWORD]);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, $r['count']);
        $names = array_map(static fn (Product $p): string => $p->getName(), $this->em()->getRepository(Product::class)->findBy([], ['name' => 'ASC']));
        self::assertSame(['B1', 'GoBuy 1'], $names);
    }

    public function testCatalogueActionsRefuseNonSuppliersAndUnknownActions(): void
    {
        $client = $this->createUser('client@example.com');
        $this->signInAdmin($this->adminWith(['products.remove', 'products.delete']));

        $this->api('POST', '/admin/api/fournisseurs/'.$client->getId()->toRfc4122().'/catalogue', ['action' => 'remove', 'reason' => 'Test test', 'password' => self::PASSWORD]);
        self::assertSame(404, $this->httpStatus());

        $a = $this->supplier();
        $r = $this->api('POST', '/admin/api/fournisseurs/'.$a->getId()->toRfc4122().'/catalogue', ['action' => 'explode', 'password' => self::PASSWORD]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_action', $r['error']);
    }
}
