<?php

namespace App\Tests\Moderation;

use App\Account\AccountPurger;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\DeletionDelay;
use App\Enum\DeletionOrigin;
use App\Enum\OrderStatus;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\ModerationHelpers;
use App\Tests\Support\SupplierFixtures;
use Symfony\Component\Clock\Clock;

/**
 * Suppression définitive d'un compte client ou fournisseur décidée par l'équipe, avec le délai choisi (immédiat, 24 h… 1 an).
 */
final class AccountDeletionByAdminTest extends AppTestCase
{
    use ModerationHelpers;
    use SupplierFixtures;

    private const REASON = 'Compte frauduleux confirmé après enquête.';

    private function url(User $target): string
    {
        return '/admin/api/comptes/'.$this->id($target).'/suppression';
    }

    private function schedule(User $target, string $delay, array $extra = []): array
    {
        return $this->api('POST', $this->url($target), $extra + ['action' => 'schedule', 'delay' => $delay, 'reason' => self::REASON, 'password' => self::PASSWORD]);
    }

    private function purge(string $modify): array
    {
        static::mockTime(Clock::get()->now()->modify($modify));

        return static::getContainer()->get(AccountPurger::class)->purgeDue(Clock::get()->now(), 200, false);
    }

    private function exists(User $user): bool
    {
        $this->em()->clear();

        return null !== $this->em()->find(User::class, $user->getId());
    }

    // ------------------------------------------------------------------ Droits et validation

    public function testItNeedsTheRightThePasswordAValidDelayAndAReason(): void
    {
        $client = $this->createUser('acheteur@example.com');
        $this->signInAdmin($this->adminWith([]));
        $this->schedule($client, '72h');
        self::assertSame(403, $this->httpStatus(), 'Le profil Support n\'a pas « accounts.delete ».');

        $this->newBrowser();
        $this->signInAdmin($this->adminWith(['accounts.delete'], 'suppr@example.com'));
        $r = $this->schedule($client, '72h', ['password' => 'faux']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('step_up_failed', $r['error']);

        $r = $this->schedule($client, '12h');
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_delay', $r['error']);

        $r = $this->schedule($client, '72h', ['reason' => 'non']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('reason_required', $r['error']);

        $r = $this->api('POST', $this->url($client), ['action' => 'jeter', 'password' => self::PASSWORD]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_action', $r['error']);

        $other = $this->createAdminUser('autre@example.com', 'finance');
        $r = $this->schedule($other, '72h');
        self::assertSame(403, $this->httpStatus());
        self::assertSame('cannot_target_admin', $r['error']);

        $this->api('POST', '/admin/api/comptes/00000000-0000-4000-8000-000000000000/suppression', ['action' => 'schedule', 'delay' => '72h', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(404, $this->httpStatus());
        self::assertTrue($this->fresh($client)->isActive(), 'Aucun refus ne doit avoir touché au compte.');
    }

    public function testEveryDelayGivesTheExpectedDate(): void
    {
        $now = new \DateTimeImmutable('2026-01-31 10:00:00+00:00');
        self::assertEquals($now, DeletionDelay::Immediate->dueFrom($now));
        self::assertSame('2026-02-01 10:00', DeletionDelay::Hours24->dueFrom($now)->format('Y-m-d H:i'));
        self::assertSame('2026-02-02 10:00', DeletionDelay::Hours48->dueFrom($now)->format('Y-m-d H:i'));
        self::assertSame('2026-02-03 10:00', DeletionDelay::Hours72->dueFrom($now)->format('Y-m-d H:i'));
        self::assertSame('2026-02-07 10:00', DeletionDelay::Days7->dueFrom($now)->format('Y-m-d H:i'));
        self::assertSame('2026-02-14 10:00', DeletionDelay::Days14->dueFrom($now)->format('Y-m-d H:i'));
        self::assertSame('2026-03-02 10:00', DeletionDelay::Days30->dueFrom($now)->format('Y-m-d H:i'));
        self::assertSame('2026-05-01 10:00', DeletionDelay::Months3->dueFrom($now)->format('Y-m-d H:i'), '31 janvier + 3 mois.');
        self::assertSame('2026-07-31 10:00', DeletionDelay::Months6->dueFrom($now)->format('Y-m-d H:i'));
        self::assertSame('2027-01-31 10:00', DeletionDelay::Year1->dueFrom($now)->format('Y-m-d H:i'));
    }

    // ------------------------------------------------------------------ Suppression programmée

    public function testScheduledDeletionClosesTheAccountNowAndErasesItAtTheDeadline(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $client = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $number = $this->orderFor($client, [[$supplier, 'Casque', 50000, 1]], OrderStatus::Delivered);
        $this->signInAdmin($this->adminWith(['accounts.delete']));

        $r = $this->schedule($client, '72h');
        self::assertSame(200, $this->httpStatus());
        self::assertFalse($r['purged']);
        $expected = Clock::get()->now()->modify('+72 hours');
        self::assertSame($expected->getTimestamp(), (new \DateTimeImmutable($r['scheduledAt']))->getTimestamp());

        $scheduled = $this->fresh($client);
        self::assertSame(AccountStatus::PendingDeletion, $scheduled->getStatus());
        self::assertTrue($scheduled->isDeletionByAdmin());
        self::assertSame(DeletionOrigin::Admin, $scheduled->getDeletionOrigin());
        self::assertSame(self::REASON, $scheduled->getDeletionReason());
        self::assertSame($expected->getTimestamp(), $scheduled->getDeletionScheduledAt()?->getTimestamp());
        self::assertContains('admin.account.deletion_scheduled', $this->actions('admin.account'));
        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage(0), self::REASON);

        // Compte fermé : pas de « restauration » possible comme après une demande du titulaire.
        $this->newBrowser();
        $r = $this->api('POST', '/auth/login', ['email' => 'acheteur@example.com', 'password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('account_closing', $r['error']);
        self::assertSame(AccountStatus::PendingDeletion, $this->fresh($client)->getStatus());

        // Avant l'échéance : rien n'est effacé.
        self::assertSame(['due' => 0, 'purged' => 0, 'failed' => 0], $this->purge('+71 hours'));
        self::assertTrue($this->exists($client));

        // Après l'échéance : le compte disparaît, la commande est anonymisée mais conservée.
        self::assertSame(['due' => 1, 'purged' => 1, 'failed' => 0], $this->purge('+2 hours'));
        self::assertFalse($this->exists($client));
        $order = $this->order($number);
        self::assertNull($order->getClient());
        self::assertNull($order->getCustomerEmail());
    }

    public function testTheAdministratorCanCancelAndTheAccountGetsItsPreviousStatusBack(): void
    {
        $suspended = $this->createUser('suspendu@example.com');
        $banned = $this->supplierAccount('banni@boutique.mg');
        $active = $this->createUser('actif@example.com', phone: '+261341234502', phoneVerified: true);
        $this->signInAdmin($this->createSuperAdmin());
        $this->api('POST', '/admin/api/utilisateurs/'.$this->id($suspended).'/statut', ['action' => 'suspend']);
        self::assertSame(200, $this->httpStatus());
        $this->api('POST', '/admin/api/comptes/'.$this->id($banned).'/bannissement', ['action' => 'ban', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());

        foreach ([[$suspended, AccountStatus::Suspended], [$banned, AccountStatus::Banned], [$active, AccountStatus::Active]] as [$user, $before]) {
            $this->schedule($user, '14d');
            self::assertSame(200, $this->httpStatus());
            self::assertSame(AccountStatus::PendingDeletion, $this->fresh($user)->getStatus());

            $this->schedule($user, '14d');
            self::assertSame(409, $this->httpStatus(), 'Déjà programmée : on annule avant de reprogrammer.');

            $this->api('POST', $this->url($user), ['action' => 'cancel', 'password' => self::PASSWORD]);
            self::assertSame(200, $this->httpStatus());
            $back = $this->fresh($user);
            self::assertSame($before, $back->getStatus());
            self::assertNull($back->getDeletionScheduledAt());
            self::assertNull($back->getDeletionOrigin());
            self::assertNull($back->getDeletionReason());

            $r = $this->api('POST', $this->url($user), ['action' => 'cancel', 'password' => self::PASSWORD]);
            self::assertSame(409, $this->httpStatus());
            self::assertSame('no_deletion_scheduled', $r['error']);
        }
        self::assertTrue($this->fresh($banned)->isBanned(), 'Un compte banni reste banni après l\'annulation.');
        self::assertContains('admin.account.deletion_cancelled', $this->actions('admin.account'));

        $this->newBrowser();
        $this->signIn($this->fresh($active));
    }

    public function testTheTeamCanOverrideADeletionRequestedByTheClientButNotCancelIt(): void
    {
        $client = $this->createUser('acheteur@example.com');
        $user = $this->em()->find(User::class, $client->getId());
        $user->requestDeletion(new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();
        $this->signInAdmin($this->adminWith(['accounts.delete']));

        // La suppression venait du titulaire : rien à « annuler » côté équipe.
        $this->api('POST', $this->url($client), ['action' => 'cancel', 'password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());

        // L'équipe peut en revanche la remplacer par un délai plus court.
        $this->schedule($client, '24h');
        self::assertSame(200, $this->httpStatus());
        $after = $this->fresh($client);
        self::assertTrue($after->isDeletionByAdmin());
        self::assertLessThan(Clock::get()->now()->modify('+25 hours')->getTimestamp(), $after->getDeletionScheduledAt()->getTimestamp());
    }

    // ------------------------------------------------------------------ Suppression immédiate

    public function testImmediateDeletionErasesTheAccountAtOnce(): void
    {
        $client = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $this->signInAdmin($this->adminWith(['accounts.delete']));

        $r = $this->schedule($client, 'immediate');

        self::assertSame(200, $this->httpStatus());
        self::assertTrue($r['purged']);
        self::assertFalse($this->exists($client));
        self::assertEmailCount(1);
        self::assertContains('account.purged', $this->actions('account.'));
        self::assertContains('admin.account.deletion_scheduled', $this->actions('admin.account'));
    }

    public function testImmediateDeletionIsRefusedWhileOrdersAreInProgress(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $client = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $this->orderFor($client, [[$supplier, 'Casque', 50000, 1]], OrderStatus::Preparing);
        $this->signInAdmin($this->createSuperAdmin());

        foreach ([$client, $supplier] as $target) {
            $r = $this->schedule($target, 'immediate');
            self::assertSame(409, $this->httpStatus());
            self::assertSame('open_orders', $r['error']);
            self::assertStringContainsString('1', $r['message']);
            self::assertTrue($this->fresh($target)->isActive(), 'Refusé : le compte n\'est pas fermé.');
        }

        // Une fois les articles du fournisseur remboursés (commande annulée), la suppression immédiate passe.
        $this->api('POST', '/admin/api/comptes/'.$this->id($supplier).'/remboursements', ['scope' => 'open', 'reason' => self::REASON, 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        $r = $this->schedule($supplier, 'immediate');
        self::assertSame(200, $this->httpStatus());
        self::assertTrue($r['purged']);
        self::assertFalse($this->exists($supplier));
    }

    // ------------------------------------------------------------------ Fournisseurs

    public function testDeletingASupplierRemovesItsShopButKeepsPastOrders(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $this->productFor($supplier, 'Casque', 1000);
        $this->productFor($supplier, 'Montre', 2000);
        $buyer = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $number = $this->orderFor($buyer, [[$supplier, 'Casque', 50000, 2]], OrderStatus::Delivered);
        $this->signInAdmin($this->adminWith(['accounts.delete']));

        $this->schedule($supplier, '7d');
        self::assertSame(200, $this->httpStatus());
        $this->client->request('GET', '/produit/'.$this->em()->getRepository(Product::class)->findOneBy([])->getId()->toRfc4122());
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Fournisseur en cours de suppression : plus rien en vente.');

        self::assertSame(['due' => 1, 'purged' => 1, 'failed' => 0], $this->purge('+8 days'));

        self::assertFalse($this->exists($supplier));
        self::assertSame(0, $this->em()->getRepository(Product::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(SupplierProfile::class)->count([]));
        $order = $this->order($number);
        self::assertCount(1, $order->getItems());
        $item = $order->getItems()->first();
        self::assertInstanceOf(OrderItem::class, $item);
        self::assertNull($item->getSupplier(), 'La ligne reste, sans lien vers le fournisseur effacé.');
        self::assertSame(100000, $item->getLineTotal());
        self::assertNotNull($order->getClient(), 'Le client de la commande n\'est pas touché.');
    }

    public function testAnOpenOrderPostponesTheErasureOfASupplier(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $buyer = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $number = $this->orderFor($buyer, [[$supplier, 'Casque', 50000, 1]], OrderStatus::OutForDelivery);
        $this->signInAdmin($this->adminWith(['accounts.delete']));
        $this->schedule($supplier, '24h');
        self::assertSame(200, $this->httpStatus());

        self::assertSame(['due' => 1, 'purged' => 0, 'failed' => 0], $this->purge('+2 days'));
        self::assertTrue($this->exists($supplier), 'Un colis est en route : le compte attend.');

        $this->em()->getConnection()->executeStatement("UPDATE orders SET status = 'delivered' WHERE order_number = :n", ['n' => $number]);
        self::assertSame(['due' => 1, 'purged' => 1, 'failed' => 0], $this->purge('+1 hour'));
        self::assertFalse($this->exists($supplier));
    }

    public function testAdministratorsAreNeverPurged(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'finance');
        $this->em()->getConnection()->executeStatement("UPDATE users SET status = 'pending_deletion', deletion_origin = 'admin', deletion_scheduled_at = now() - interval '1 day' WHERE email = 'admin@example.com'");
        $this->em()->clear();

        self::assertSame(['due' => 0, 'purged' => 0, 'failed' => 0], static::getContainer()->get(AccountPurger::class)->purgeDue(new \DateTimeImmutable(), 200, false));
        self::assertTrue($this->exists($admin));
    }

    // ------------------------------------------------------------------ Écran

    public function testAccountPageShowsTheDeletionPanelsOnlyWithTheRight(): void
    {
        $client = $this->createUser('acheteur@example.com');
        $this->signInAdmin($this->adminWith(['users.view']));
        $this->client->request('GET', '/admin/comptes/'.$this->id($client));
        self::assertStringNotContainsString('data-panel="deletion"', (string) $this->client->getResponse()->getContent());

        $this->newBrowser();
        $this->signInAdmin($this->adminWith(['users.view', 'accounts.delete'], 'suppr@example.com'));
        $this->client->request('GET', '/admin/comptes/'.$this->id($client));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-panel="deletion"', $html);
        foreach (DeletionDelay::cases() as $delay) {
            self::assertStringContainsString('value="'.$delay->value.'"', $html);
        }
        self::assertStringNotContainsString('data-deletion-banner', $html);

        $this->schedule($client, '48h');
        $this->client->request('GET', '/admin/comptes/'.$this->id($client));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-deletion-banner', $html);
        self::assertStringContainsString('value="cancel"', $html, 'Le formulaire propose maintenant d\'annuler.');
        self::assertStringNotContainsString('name="delay"', $html);
    }

    public function testNewTextsExistInEveryLanguage(): void
    {
        $translator = static::getContainer()->get('translator');
        foreach (['fr', 'en', 'es'] as $locale) {
            foreach ([['sanction.deletion.help', 'sanctions'], ['sanction.delay.3m', 'sanctions'], ['sanction.delay.immediate', 'sanctions'], ['error.open_orders', 'sanctions'], ['perm.accounts.delete', 'admin'], ['error.account_closing', 'auth'], ['moderation.deletion_scheduled.body', 'emails'], ['moderation.deletion_cancelled.subject', 'emails']] as [$key, $domain]) {
                self::assertNotSame($key, $translator->trans($key, [], $domain, $locale), $locale.' '.$domain.' '.$key);
            }
            foreach (DeletionDelay::cases() as $delay) {
                self::assertNotSame('sanction.delay.'.$delay->value, $translator->trans('sanction.delay.'.$delay->value, [], 'sanctions', $locale), $locale.' '.$delay->value);
            }
        }
    }
}
