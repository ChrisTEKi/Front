<?php

namespace App\Tests\Moderation;

use App\Entity\AuditLog;
use App\Entity\BlacklistEntry;
use App\Entity\User;
use App\Enum\BlacklistKind;
use App\Enum\SupplierStatus;
use App\Moderation\BanOptions;
use App\Moderation\ModerationException;
use App\Moderation\SanctionService;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\ModerationHelpers;
use App\Tests\Support\SupplierFixtures;

/**
 * Non-régression : défauts relevés à la revue des sanctions (bannissement, liste noire, suppression, suspension fournisseur).
 * Chaque test décrit le défaut qu'il empêche de revenir.
 */
final class SanctionReviewTest extends AppTestCase
{
    use ModerationHelpers;
    use SupplierFixtures;

    private const REASON = 'Fraude avérée : faux produits vendus à plusieurs clients.';

    private function ban(User $target, array $extra = []): array
    {
        return $this->api('POST', '/admin/api/comptes/'.$this->id($target).'/bannissement', $extra + ['action' => 'ban', 'reason' => self::REASON, 'password' => self::PASSWORD]);
    }

    private function delete(User $target, string $delay = 'immediate'): array
    {
        return $this->api('POST', '/admin/api/comptes/'.$this->id($target).'/suppression', ['action' => 'schedule', 'delay' => $delay, 'reason' => 'Compte frauduleux confirmé après enquête.', 'password' => self::PASSWORD]);
    }

    private function blacklist(array $data): array
    {
        return $this->api('POST', '/admin/api/liste-noire', $data + ['reason' => 'Fraudeur connu', 'password' => self::PASSWORD]);
    }

    /** @return list<BlacklistEntry> */
    private function entries(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(BlacklistEntry::class)->findBy([], ['createdAt' => 'ASC']);
    }

    /** @return array<string, mixed>|null métadonnées de la dernière ligne du journal pour cette action */
    private function lastAudit(string $action): ?array
    {
        $this->em()->clear();
        $found = null;
        foreach ($this->em()->getRepository(AuditLog::class)->findBy([], ['createdAt' => 'ASC']) as $log) {
            if ($log->getAction() === $action) {
                $found = $log->getMetadata();
            }
        }

        return $found;
    }

    // ------------------------------------------------------------------ Bannissement : options contrôlées AVANT d'agir

    public function testABanWithAnInvalidBlacklistDurationBansNobody(): void
    {
        $client = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $this->signInAdmin($this->createSuperAdmin());

        foreach ([0, -5, 3651] as $days) {
            $r = $this->ban($client, ['blacklist' => ['email'], 'blacklistDays' => $days]);
            self::assertSame(422, $this->httpStatus(), 'Durée '.$days);
            self::assertSame('invalid_days', $r['error'], 'Durée '.$days);
            self::assertStringNotContainsString('%max%', $r['message']);
        }

        // Une adresse IP est plafonnée plus bas (365 jours) : la même durée de 400 jours est refusée dès qu'une IP est cochée.
        $r = $this->ban($client, ['blacklist' => ['email', 'ip'], 'blacklistDays' => 400]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_days', $r['error']);
        self::assertStringContainsString('365', $r['message']);

        $after = $this->fresh($client);
        self::assertFalse($after->isBanned(), 'Un choix invalide ne doit JAMAIS laisser un bannissement à moitié appliqué.');
        self::assertTrue($after->isActive());
        self::assertCount(0, $this->entries());
        self::assertSame([], array_filter($this->actions('admin.account'), static fn (string $a): bool => 'admin.account.banned' === $a));
        self::assertEmailCount(0);

        // Une durée valable passe, et la liste noire reçoit bien une échéance.
        $this->ban($client, ['blacklist' => ['email'], 'blacklistDays' => 90]);
        self::assertSame(200, $this->httpStatus());
        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertNotNull($entries[0]->getExpiresAt());
    }

    public function testTheServiceItselfRefusesInvalidOptionsBeforeBanning(): void
    {
        // Défense en profondeur : même un autre appelant que la route (commande, futur écran) ne peut pas bannir à moitié.
        $client = $this->createUser('acheteur@example.com');
        $admin = $this->createSuperAdmin();
        $service = static::getContainer()->get(SanctionService::class);

        foreach ([new BanOptions('none', 'none', [BlacklistKind::Email], 0), new BanOptions('none', 'none', [BlacklistKind::Ip], 366), new BanOptions('tout-casser')] as $options) {
            try {
                $service->ban($this->em()->find(User::class, $admin->getId()), $this->em()->find(User::class, $client->getId()), self::REASON, $options);
                self::fail('Les options invalides auraient dû être refusées.');
            } catch (ModerationException $e) {
                self::assertContains($e->reason, ['invalid_days', 'invalid_option']);
            }
            self::assertFalse($this->fresh($client)->isBanned());
        }
    }

    public function testBanningAnAccountWithADeletionScheduledIsWrittenInTheJournal(): void
    {
        $client = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $this->signInAdmin($this->createSuperAdmin());

        $this->delete($client, '72h');
        self::assertSame(200, $this->httpStatus());
        $this->ban($client);
        self::assertSame(200, $this->httpStatus());

        $meta = $this->lastAudit('admin.account.banned');
        self::assertNotNull($meta);
        self::assertTrue($meta['deletion_cancelled'], 'Le bannissement remplace la suppression en attente : la trace doit le dire.');
        $after = $this->fresh($client);
        self::assertTrue($after->isBanned());
        self::assertNull($after->getDeletionScheduledAt() ?? null, 'Plus aucune suppression programmée.');
    }

    public function testBanningAnOrdinaryAccountDoesNotClaimToCancelADeletion(): void
    {
        $client = $this->createUser('acheteur@example.com');
        $this->signInAdmin($this->createSuperAdmin());
        $this->ban($client);

        self::assertFalse($this->lastAudit('admin.account.banned')['deletion_cancelled']);
    }

    // ------------------------------------------------------------------ Liste noire : un renouvellement ne raccourcit jamais

    public function testRenewingABlacklistEntryNeverShortensIt(): void
    {
        $this->signInAdmin($this->adminWith(['blacklist.manage']));

        // 30 jours puis 5 jours : on garde l'échéance la plus lointaine.
        $this->blacklist(['kind' => 'email', 'value' => 'fraudeur@example.com', 'days' => 30]);
        $first = $this->entries()[0]->getExpiresAt();
        self::assertNotNull($first);
        $this->blacklist(['kind' => 'email', 'value' => 'fraudeur@example.com', 'days' => 5, 'reason' => 'Nouveau motif connu']);
        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertEquals($first, $entries[0]->getExpiresAt(), 'Une durée plus courte ne réduit pas l\'interdiction en cours.');
        self::assertSame('Nouveau motif connu', $entries[0]->getReason(), 'Le motif, lui, est bien mis à jour.');

        // Plus longue : elle est prolongée.
        $this->blacklist(['kind' => 'email', 'value' => 'fraudeur@example.com', 'days' => 200]);
        self::assertGreaterThan($first, $this->entries()[0]->getExpiresAt());

        // Définitive : elle l'emporte, et ne redevient jamais temporaire.
        $this->blacklist(['kind' => 'email', 'value' => 'fraudeur@example.com']);
        self::assertNull($this->entries()[0]->getExpiresAt());
        $this->blacklist(['kind' => 'email', 'value' => 'fraudeur@example.com', 'days' => 3]);
        self::assertNull($this->entries()[0]->getExpiresAt(), 'Une interdiction définitive ne devient pas temporaire par un nouvel ajout.');
        self::assertCount(1, $this->entries());
    }

    // ------------------------------------------------------------------ Compte banni puis supprimé : identifiants gardés

    public function testABannedAccountThatIsThenDeletedKeepsItsEmailAndPhoneBlacklisted(): void
    {
        $client = $this->createUser('fraudeur@example.com', phone: '+261341234501', phoneVerified: true);
        $this->signInAdmin($this->createSuperAdmin());

        $this->ban($client);
        self::assertSame(200, $this->httpStatus());
        self::assertCount(0, $this->entries(), 'Aucune liste noire demandée au bannissement.');

        $r = $this->delete($client);
        self::assertSame(200, $this->httpStatus());
        self::assertTrue($r['purged']);
        self::assertNull($this->em()->find(User::class, $client->getId()), 'Le compte est bien effacé.');

        $values = [];
        foreach ($this->entries() as $entry) {
            self::assertNull($entry->getExpiresAt(), 'Entrées définitives.');
            $values[$entry->getKind()->value] = $entry->getValue();
        }
        self::assertSame('fraudeur@example.com', $values['email'] ?? null);
        self::assertSame('+261341234501', $values['phone'] ?? null);
        self::assertSame([BlacklistKind::Email->value, BlacklistKind::Phone->value], array_keys($values), 'Ni nom ni IP : seulement de quoi empêcher la ré-inscription.');

        $this->newBrowser();
        $this->api('POST', '/auth/register/client', ['fullName' => 'Rakoto Paul', 'email' => 'fraudeur@example.com', 'password' => self::PASSWORD, 'phone' => '']);
        self::assertSame(403, $this->httpStatus(), 'Le compte supprimé ne libère pas son adresse e-mail.');
    }

    public function testAnAccountDeletedWithoutBanFreesItsIdentifiersAsBefore(): void
    {
        $client = $this->createUser('acheteur@example.com', phone: '+261341234501', phoneVerified: true);
        $this->signInAdmin($this->adminWith(['accounts.delete']));

        $r = $this->delete($client);
        self::assertSame(200, $this->httpStatus());
        self::assertTrue($r['purged']);
        self::assertCount(0, $this->entries(), 'Sans bannissement, rien n\'est conservé.');

        $this->newBrowser();
        $this->api('POST', '/auth/register/client', ['fullName' => 'Rakoto Paul', 'email' => 'acheteur@example.com', 'password' => self::PASSWORD, 'phone' => '']);
        self::assertSame(200, $this->httpStatus(), 'Un compte supprimé librement peut se ré-inscrire.');
    }

    // ------------------------------------------------------------------ Suspension d'un fournisseur : prévenu et tracé comme tel

    public function testSuspendingASupplierWarnsItAndIsJournalledAsASuspension(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'approved', '+261331112233', 'Ma Boutique');
        $this->signInAdmin($this->adminWith(['suppliers.approve']));

        $r = $this->api('POST', '/admin/api/fournisseurs/'.$this->id($supplier).'/decision', ['action' => 'suspend', 'reason' => 'Plaintes répétées de clients']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(SupplierStatus::Suspended->value, $r['status']);

        self::assertEmailCount(1);
        $mail = self::getMailerMessage(0);
        self::assertEmailHeaderSame($mail, 'To', 'Test Fournisseur <marie@boutique.mg>');
        self::assertEmailTextBodyContains($mail, 'Plaintes répétées de clients');
        self::assertEmailTextBodyContains($mail, 'Ma Boutique');

        $actions = $this->actions('admin.supplier');
        self::assertContains('admin.supplier.suspended', $actions);
        self::assertNotContains('admin.supplier.rejected', $actions, 'Une suspension ne doit plus être journalisée comme un refus.');
    }

    // ------------------------------------------------------------------ Liens du tableau de bord

    public function testTheAccountLinkIsShownToAProfileThatMayOnlyBanAndView(): void
    {
        $this->createUser('acheteur@example.com');
        $this->signInAdmin($this->adminWith(['sanctions.ban', 'users.view']));

        $this->client->request('GET', '/admin?tab=users_table');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('/admin/comptes/', $html, 'Le lien « Compte » doit être visible : c\'est par là qu\'on bannit.');
    }
}
