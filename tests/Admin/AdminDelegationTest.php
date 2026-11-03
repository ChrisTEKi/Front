<?php

namespace App\Tests\Admin;

use App\Entity\AdminRole;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\Permission;
use App\Tests\Support\AppTestCase;

/**
 * Droits délégués (profils « PDG » et « DG ») et suspension / réactivation GROUPÉE d'administrateurs (Super Admin seul).
 */
final class AdminDelegationTest extends AppTestCase
{
    private const BULK = '/admin/api/administrateurs/statut-groupe';

    private function role(string $code): AdminRole
    {
        $this->em()->clear();

        return $this->em()->getRepository(AdminRole::class)->findOneBy(['code' => $code]) ?? throw new \LogicException('Profil introuvable : '.$code);
    }

    private function statusOf(User $user): AccountStatus
    {
        $this->em()->clear();

        return $this->em()->find(User::class, $user->getId())->getStatus();
    }

    private function id(User $user): string
    {
        return $user->getId()->toRfc4122();
    }

    /** @param list<string> $ids @param array<string, mixed> $extra @return array<string, mixed> */
    private function bulk(array $ids, string $action = 'suspend', array $extra = []): array
    {
        return $this->api('POST', self::BULK, $extra + ['ids' => $ids, 'action' => $action, 'password' => self::PASSWORD]);
    }

    private function logCount(string $action): int
    {
        return $this->em()->getRepository(AuditLog::class)->count(['action' => $action]);
    }

    // ------------------------------------------------------------------ Profils PDG et DG

    public function testPdgAndDgProfilesExistAndAreEditableProfilesWithoutAdminRights(): void
    {
        $pdg = $this->role('pdg');
        $dg = $this->role('dg');

        self::assertFalse($pdg->isSystem(), 'Un profil délégué reste modifiable par le Super Admin.');
        self::assertFalse($dg->isSystem());
        self::assertSame('PDG', $pdg->getLabel());
        self::assertSame('DG', $dg->getLabel());

        foreach ([$pdg, $dg] as $role) {
            foreach ($role->getPermissions() as $permission) {
                self::assertContains($permission, Permission::values(), 'Droit inconnu : '.$permission);
                self::assertStringStartsNotWith('admins.', $permission, 'Gérer les administrateurs reste réservé au Super Admin.');
            }
        }
    }

    public function testPdgHoldsEveryAssignableRightAndDgLacksOnlyTheTwoIrreversibleDeletions(): void
    {
        $everything = array_map(static fn (Permission $p): string => $p->value, Permission::assignable());
        sort($everything);

        $pdg = $this->role('pdg')->getPermissions();
        sort($pdg);
        self::assertSame($everything, $pdg, 'Le PDG a tous les droits attribuables (à tenir à jour quand un droit est ajouté).');

        $dg = $this->role('dg')->getPermissions();
        sort($dg);
        $expected = array_values(array_diff($everything, ['accounts.delete', 'products.delete']));
        self::assertSame($expected, $dg, 'Le DG a tout, sauf la suppression définitive d\'un compte et d\'un produit.');
    }

    public function testDelegatedProfilesAreAssignableByTheSuperAdminOnly(): void
    {
        $this->createSuperAdmin();
        $this->signInAdmin($this->reload($this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.com'])));

        foreach (['pdg', 'dg'] as $i => $code) {
            $r = $this->api('POST', '/admin/api/administrateurs', [
                'email' => $code.'@example.com',
                'fullName' => 'Direction '.$code,
                'roleId' => $this->role($code)->getId()->toRfc4122(),
                'extra' => [],
                'denied' => [],
                'locale' => 'fr',
                'password' => self::PASSWORD,
            ]);
            self::assertSame(201, $this->httpStatus(), 'Invitation '.$code.' : '.json_encode($r));
            static::mockTime(new \DateTimeImmutable('+'.(2 * ($i + 1)).' minutes'));
        }
    }

    public function testPdgCanScheduleAnAccountDeletionButDgCannot(): void
    {
        $client = $this->createUser('acheteur@example.com');
        $url = '/admin/api/comptes/'.$this->id($client).'/suppression';
        $payload = ['action' => 'schedule', 'delay' => '72h', 'reason' => 'Compte frauduleux confirmé après enquête.', 'password' => self::PASSWORD];

        $this->signInAdmin($this->createAdminUser('dg@example.com', 'dg'));
        $this->api('POST', $url, $payload);
        self::assertSame(403, $this->httpStatus(), 'Le DG n\'a pas « accounts.delete ».');
        self::assertSame(AccountStatus::Active, $this->statusOf($client));

        $this->newBrowser();
        $this->signInAdmin($this->createAdminUser('pdg@example.com', 'pdg'));
        $this->api('POST', $url, $payload);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(AccountStatus::PendingDeletion, $this->statusOf($client));
    }

    public function testDgCanBanAFreezeAndBlacklistLikeThePdg(): void
    {
        $client = $this->createUser('acheteur@example.com');
        $this->signInAdmin($this->createAdminUser('dg@example.com', 'dg'));

        $this->api('POST', '/admin/api/comptes/'.$this->id($client).'/bannissement', [
            'action' => 'ban',
            'reason' => 'Fraude à la carte confirmée.',
            'catalog' => 'none', 'refund' => 'none', 'blacklist' => [], 'password' => self::PASSWORD,
        ]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(AccountStatus::Banned, $this->statusOf($client));
    }

    // ------------------------------------------------------------------ Suspension groupée

    public function testSuperAdminSuspendsSeveralAdminsAtOnceAndTheirSessionsEnd(): void
    {
        $this->createSuperAdmin();
        $a = $this->createAdminUser('a@example.com', 'pdg');
        $b = $this->createAdminUser('b@example.com', 'dg');
        $c = $this->createAdminUser('c@example.com', 'support');

        // Un des administrateurs est connecté : sa session doit tomber dès la suspension.
        $this->signInAdmin($a);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $cookiesOfA = $this->client->getCookieJar()->all();

        $this->newBrowser();
        $this->signInAdmin($this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.com']));
        $r = $this->bulk([$this->id($a), $this->id($b)]);

        self::assertSame(200, $this->httpStatus(), json_encode($r));
        self::assertSame(2, $r['changed']);
        self::assertSame([], $r['skipped']);
        self::assertSame(AccountStatus::Suspended, $this->statusOf($a));
        self::assertSame(AccountStatus::Suspended, $this->statusOf($b));
        self::assertSame(AccountStatus::Active, $this->statusOf($c), 'Un administrateur non sélectionné n\'est pas touché.');

        self::assertSame(2, $this->logCount('admin.suspended'), 'Une ligne d\'audit par administrateur…');
        self::assertSame(1, $this->logCount('admin.bulk_status'), '…plus une ligne récapitulative.');

        // Le navigateur de A (ses cookies de session) est renvoyé à la connexion : sa session n'est plus valable.
        $this->client->getCookieJar()->clear();
        foreach ($cookiesOfA as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects();
    }

    public function testBulkReactivationBringsSuspendedAdminsBack(): void
    {
        $this->createSuperAdmin();
        $a = $this->createAdminUser('a@example.com', 'pdg');
        $b = $this->createAdminUser('b@example.com', 'dg');
        $this->signInAdmin($this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.com']));

        $this->bulk([$this->id($a), $this->id($b)]);
        $r = $this->bulk([$this->id($a), $this->id($b)], 'reactivate');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, $r['changed']);
        self::assertSame(AccountStatus::Active, $this->statusOf($a));
        self::assertSame(AccountStatus::Active, $this->statusOf($b));
        self::assertSame(2, $this->logCount('admin.reactivated'));
    }

    public function testBulkSkipsWhatCannotBeProcessedWithoutBlockingTheOthers(): void
    {
        $root = $this->createSuperAdmin();
        $ok = $this->createAdminUser('ok@example.com', 'pdg');
        $already = $this->createAdminUser('deja@example.com', 'dg');
        $invited = $this->createAdminUser('invite@example.com', 'support');
        $client = $this->createUser('client@example.com');
        $this->signInAdmin($this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.com']));

        $this->bulk([$this->id($already)]);
        $this->api('POST', '/admin/api/administrateurs/'.$this->id($invited).'/reinitialisation', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());

        $unknown = '0192ffff-ffff-7fff-8fff-ffffffffffff';
        $r = $this->bulk([$this->id($ok), $this->id($already), $this->id($invited), $this->id($root), $this->id($client), $unknown, 'pas-un-uuid']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(1, $r['changed']);
        $reasons = array_column($r['skipped'], 'reason', 'id');
        self::assertSame('bulk_already_suspended', $reasons[$this->id($already)]);
        self::assertSame('bulk_invited', $reasons[$this->id($invited)]);
        self::assertSame('cannot_modify_super_admin', $reasons[$this->id($root)]);
        self::assertSame('not_found', $reasons[$this->id($client)], 'Un client n\'est jamais géré par cette route.');
        self::assertSame('not_found', $reasons[$unknown]);
        self::assertSame('not_found', $reasons['pas-un-uuid']);
        foreach ($r['skipped'] as $skip) {
            self::assertNotSame('', $skip['message']);
            self::assertStringNotContainsString('bulk.skip.', $skip['message'], 'Le motif est traduit.');
        }

        self::assertSame(AccountStatus::Suspended, $this->statusOf($ok));
        self::assertSame(AccountStatus::Active, $this->statusOf($client), 'Le client n\'est pas suspendu.');
        self::assertSame(AccountStatus::Active, $this->statusOf($root));
    }

    public function testBulkNeedsThePasswordAndLeavesEverythingUntouchedOtherwise(): void
    {
        $this->createSuperAdmin();
        $a = $this->createAdminUser('a@example.com', 'pdg');
        $this->signInAdmin($this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.com']));

        $r = $this->bulk([$this->id($a)], 'suspend', ['password' => 'mauvais-mot-de-passe']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('step_up_failed', $r['error']);
        self::assertSame(AccountStatus::Active, $this->statusOf($a));
        self::assertSame(0, $this->logCount('admin.bulk_status'));

        $r = $this->bulk([$this->id($a)], 'suspend', ['password' => '']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame(AccountStatus::Active, $this->statusOf($a));
    }

    public function testBulkValidation(): void
    {
        $this->createSuperAdmin();
        $a = $this->createAdminUser('a@example.com', 'pdg');
        $this->signInAdmin($this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.com']));

        $r = $this->bulk([]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('bulk_empty', $r['error']);

        $r = $this->bulk([$this->id($a)], 'bannir');
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_action', $r['error']);

        $r = $this->api('POST', self::BULK, ['ids' => 'pas-une-liste', 'action' => 'suspend', 'password' => self::PASSWORD]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('bulk_empty', $r['error']);

        $tooMany = array_map(static fn (int $i): string => sprintf('0192aaaa-aaaa-7aaa-8aaa-%012d', $i), range(1, 51));
        $r = $this->bulk($tooMany);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('bulk_too_many', $r['error']);
        self::assertStringContainsString('50', $r['message']);
        self::assertSame(AccountStatus::Active, $this->statusOf($a));
    }

    public function testBulkIsRefusedToEveryAdminWhoIsNotTheSuperAdminEvenWithAllRights(): void
    {
        $this->createSuperAdmin();
        $target = $this->createAdminUser('cible@example.com', 'dg');
        $pdg = $this->createAdminUser('pdg@example.com', 'pdg');
        $this->signInAdmin($pdg);

        $this->bulk([$this->id($target)]);
        self::assertSame(403, $this->httpStatus(), 'Gérer les administrateurs reste réservé au Super Admin.');
        self::assertSame(AccountStatus::Active, $this->statusOf($target));
    }

    public function testBulkIsRefusedToCustomersAndToVisitors(): void
    {
        $this->createSuperAdmin();
        $target = $this->createAdminUser('cible@example.com', 'dg');

        $this->bulk([$this->id($target)]);
        self::assertContains($this->httpStatus(), [401, 403], 'Visiteur anonyme.');

        $this->signIn($this->createUser('client@example.com'));
        $this->bulk([$this->id($target)]);
        self::assertContains($this->httpStatus(), [401, 403]);
        self::assertSame(AccountStatus::Active, $this->statusOf($target));
    }

    // ------------------------------------------------------------------ Interface

    public function testOnlyTheSuperAdminSeesTheSelectionControls(): void
    {
        $this->createSuperAdmin();
        $this->createAdminUser('a@example.com', 'pdg');
        $this->signInAdmin($this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.com']));

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-admin-bulk]');
        self::assertSelectorExists('[data-admin-select-all]');
        self::assertSelectorExists('[data-admin-select]');
        self::assertStringContainsString('adminStatusBulk', (string) $this->client->getResponse()->getContent());

        $this->newBrowser();
        $this->signInAdmin($this->createAdminUser('pdg@example.com', 'pdg'));
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-admin-bulk]', 'Le PDG ne gère pas les administrateurs.');
        self::assertSelectorNotExists('[data-admin-select]');
    }
}
