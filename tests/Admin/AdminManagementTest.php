<?php

namespace App\Tests\Admin;

use App\Entity\AdminProfile;
use App\Entity\AdminRole;
use App\Entity\AuditLog;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\AccountType;
use App\Enum\OtpPurpose;
use App\Enum\Permission;
use App\Tests\Support\AppTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Gestion des administrateurs (Super Admin seul), confirmation par mot de passe, profils de droits,
 * exports CSV et commandes console du Super Admin.
 */
final class AdminManagementTest extends AppTestCase
{
    /** Mot de passe choisi par un administrateur invité (≥ 14 caractères, 4 familles de caractères). */
    private const ADMIN_PASSWORD = 'Zq7!velours-Tangent-2026';

    // ------------------------------------------------------------------ Aides

    private function roleId(string $code): string
    {
        $role = $this->em()->getRepository(AdminRole::class)->findOneBy(['code' => $code]);
        self::assertNotNull($role, 'Profil introuvable : '.$code);

        return $role->getId()->toRfc4122();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function inviteBody(array $overrides = []): array
    {
        return $overrides + [
            'email' => 'nouveau@example.com',
            'fullName' => 'Nadia Ravelo',
            'roleId' => $this->roleId('support'),
            'extra' => [],
            'denied' => [],
            'locale' => 'fr',
            'password' => self::PASSWORD,
        ];
    }

    /** Super Admin connecté. */
    private function superAdmin(): User
    {
        $root = $this->createSuperAdmin();
        $this->signInAdmin($root);

        return $root;
    }

    /** Invite un administrateur et retourne son identifiant. */
    private function invite(array $overrides = []): string
    {
        $r = $this->api('POST', '/admin/api/administrateurs', $this->inviteBody($overrides));
        self::assertSame(201, $this->httpStatus(), json_encode($r));

        return $r['id'];
    }

    private function adminByEmail(string $email): User
    {
        $this->em()->clear();

        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email]) ?? throw new \LogicException('Introuvable : '.$email);
    }

    // ------------------------------------------------------------------ Invitation et activation

    public function testSuperAdminInvitesAnAdminWhoChoosesHisOwnPasswordAndSignsIn(): void
    {
        $this->superAdmin();
        $id = $this->invite();

        // Le compte existe mais n'a AUCUN mot de passe : personne (pas même le Super Admin) ne le connaît.
        $invited = $this->adminByEmail('nouveau@example.com');
        self::assertFalse($invited->hasPassword());
        self::assertSame(AccountType::Admin, $invited->getAccountType());
        self::assertFalse($invited->isSuperAdmin());
        $token = $this->otp()->lastCode(OtpPurpose::AdminInvitation);
        self::assertSame(43, \strlen($token), 'Long jeton aléatoire (256 bits), pas un code à 6 chiffres.');

        // Il ouvre le lien dans SON navigateur.
        $this->newBrowser();
        $url = '/admin/invitation/'.$id.'/'.$token;
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'), 'Le jeton est dans l\'URL : il ne doit pas fuiter vers un autre site.');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // Mot de passe faible, puis différent de sa confirmation : refusés SANS consommer le lien.
        $r = $this->api('POST', $url, ['password' => 'court', 'passwordConfirm' => 'court']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('weak_password', $r['error']);
        $r = $this->api('POST', $url, ['password' => self::ADMIN_PASSWORD, 'passwordConfirm' => self::ADMIN_PASSWORD.'x']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('password_mismatch', $r['error']);

        $r = $this->api('POST', $url, ['password' => self::ADMIN_PASSWORD, 'passwordConfirm' => self::ADMIN_PASSWORD]);
        self::assertSame(200, $this->httpStatus(), json_encode($r));
        self::assertTrue($r['ok']);
        self::assertSame('/connexion', $r['redirect']);
        self::assertTrue($this->adminByEmail('nouveau@example.com')->hasPassword());
        self::assertTrue($this->adminByEmail('nouveau@example.com')->isEmailVerified(), 'Avoir reçu le lien prouve la possession de la boîte mail.');

        // Le lien est à usage unique.
        $r = $this->api('POST', $url, ['password' => self::ADMIN_PASSWORD.'2', 'passwordConfirm' => self::ADMIN_PASSWORD.'2']);
        self::assertSame('invalid_link', $r['error']);

        // Première connexion : mot de passe + code e-mail (obligatoire), puis accès limité au profil « support ».
        $this->signInAdmin($invited, self::ADMIN_PASSWORD);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-admin-tab="admins"', (string) $this->client->getResponse()->getContent());
        $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['email' => 'autre@example.com']));
        self::assertSame(403, $this->httpStatus(), 'Un administrateur « normal » ne crée pas d\'administrateurs.');
    }

    public function testInvitationLinkIsRefusedWhenWrongOrForTheWrongAccount(): void
    {
        $this->superAdmin();
        $id = $this->invite();
        $this->newBrowser();

        $r = $this->api('POST', '/admin/invitation/'.$id.'/'.str_repeat('a', 43), ['password' => self::ADMIN_PASSWORD, 'passwordConfirm' => self::ADMIN_PASSWORD]);
        self::assertSame('invalid_link', $r['error']);

        // Un identifiant inexistant donne exactement la même réponse : on ne révèle pas quels comptes existent.
        $r = $this->api('POST', '/admin/invitation/01920000-0000-7000-8000-00000000ffff/'.str_repeat('a', 43), ['password' => self::ADMIN_PASSWORD, 'passwordConfirm' => self::ADMIN_PASSWORD]);
        self::assertSame('invalid_link', $r['error']);
        self::assertFalse($this->adminByEmail('nouveau@example.com')->hasPassword());
    }

    public function testInvitationLinkCannotTakeOverASuperAdminOrAnActiveAccount(): void
    {
        $root = $this->createSuperAdmin();
        $active = $this->createAdminUser('actif@example.com', 'support');

        foreach ([$root, $active] as $target) {
            $r = $this->api('POST', '/admin/invitation/'.$target->getId()->toRfc4122().'/'.str_repeat('b', 43), ['password' => self::ADMIN_PASSWORD, 'passwordConfirm' => self::ADMIN_PASSWORD]);
            self::assertSame('invalid_link', $r['error']);
        }
        // Les mots de passe d'origine n'ont pas bougé.
        $this->signInAdmin($root);
    }

    public function testResendInvitationOnlyForAdminsWhoHaveNotActivated(): void
    {
        $this->superAdmin();
        $id = $this->invite();
        self::assertSame(1, $this->otp()->count(OtpPurpose::AdminInvitation));
        // Le délai anti-spam entre deux envois est de 30 s : on avance l'horloge.
        static::mockTime(new \DateTimeImmutable('+2 minutes'));

        $this->api('POST', '/admin/api/administrateurs/'.$id.'/invitation', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, $this->otp()->count(OtpPurpose::AdminInvitation));

        $active = $this->createAdminUser('actif@example.com', 'compta');
        $r = $this->api('POST', '/admin/api/administrateurs/'.$active->getId()->toRfc4122().'/invitation', ['password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('already_activated', $r['error']);
    }

    // ------------------------------------------------------------------ Réinitialisation de l'accès (remplace le « mot de passe oublié »)

    public function testSuperAdminResetsAnActiveAdminsAccessWithANewActivationLink(): void
    {
        $this->superAdmin();
        $active = $this->createAdminUser('actif@example.com', 'compta');
        $id = $active->getId()->toRfc4122();

        $r = $this->api('POST', '/admin/api/administrateurs/'.$id.'/reinitialisation', ['password' => 'mauvais']);
        self::assertSame(403, $this->httpStatus(), 'La confirmation par mot de passe est exigée.');
        self::assertTrue($this->adminByEmail('actif@example.com')->hasPassword());
        self::assertSame(0, $this->otp()->count(OtpPurpose::AdminInvitation));

        $this->api('POST', '/admin/api/administrateurs/'.$id.'/reinitialisation', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());

        $after = $this->adminByEmail('actif@example.com');
        self::assertFalse($after->hasPassword(), 'Le mot de passe est supprimé : les sessions et l\'ancien accès tombent.');
        self::assertSame(1, $this->otp()->count(OtpPurpose::AdminInvitation), 'Un nouveau lien d\'activation est envoyé.');
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.access_reset']));

        // L'administrateur apparaît de nouveau « invité » : le Super Admin peut renvoyer le lien, pas le réinitialiser une 2e fois.
        $r = $this->api('POST', '/admin/api/administrateurs/'.$id.'/reinitialisation', ['password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('not_activated', $r['error']);
    }

    public function testAccessResetIsRefusedForTheSuperAdminForCustomersAndForNonSuperAdmins(): void
    {
        $root = $this->superAdmin();
        $r = $this->api('POST', '/admin/api/administrateurs/'.$root->getId()->toRfc4122().'/reinitialisation', ['password' => self::PASSWORD]);
        self::assertSame('cannot_modify_super_admin', $r['error']);
        self::assertTrue($this->adminByEmail('root@example.com')->hasPassword());

        $client = $this->createUser('client@example.com');
        $this->api('POST', '/admin/api/administrateurs/'.$client->getId()->toRfc4122().'/reinitialisation', ['password' => self::PASSWORD]);
        self::assertSame(404, $this->httpStatus());
        self::assertTrue($this->reload($client)->hasPassword());

        // Un administrateur simple (même avec tous les droits « classiques ») ne peut pas réinitialiser l'accès d'un collègue.
        $this->newBrowser();
        $other = $this->createAdminUser('autre@example.com', 'compta');
        $simple = $this->createAdminUser('simple@example.com', 'finance');
        $this->signInAdmin($simple);
        $this->api('POST', '/admin/api/administrateurs/'.$other->getId()->toRfc4122().'/reinitialisation', ['password' => self::PASSWORD]);
        self::assertContains($this->httpStatus(), [401, 403]);
        self::assertTrue($this->adminByEmail('autre@example.com')->hasPassword());
    }

    public function testInvitationValidationErrors(): void
    {
        $this->superAdmin();
        $this->createUser('pris@example.com');

        $r = $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['email' => 'pas-un-email']));
        self::assertSame('invalid_email', $r['error']);
        $r = $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['fullName' => 'A']));
        self::assertSame('invalid_name', $r['error']);
        $r = $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['email' => 'pris@example.com']));
        self::assertSame(409, $this->httpStatus());
        self::assertSame('email_taken', $r['error']);
        $r = $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['roleId' => $this->roleId('super_admin')]));
        self::assertSame('role_not_assignable', $r['error'], 'Le profil Super Admin ne s\'attribue jamais.');
        $r = $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['roleId' => 'n-importe-quoi']));
        self::assertSame(404, $this->httpStatus());
        $r = $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['extra' => ['users.view', 'droit.inconnu']]));
        self::assertSame('invalid_permission', $r['error'], 'Un droit inconnu est refusé, jamais ignoré en silence.');

        self::assertSame(0, $this->otp()->count(OtpPurpose::AdminInvitation));
    }

    public function testAdminManagementRightCanNeverBeGivenToANormalAdmin(): void
    {
        // Même si on tente de l'ajouter comme droit individuel, « admins.manage » reste réservé au Super Admin.
        $this->signInAdmin($this->createAdminUser('rusé@example.com', 'support', extra: [Permission::AdminsManage, Permission::AdminsView]));

        $this->api('POST', '/admin/api/administrateurs', $this->inviteBody());
        self::assertSame(403, $this->httpStatus());
        $this->client->request('GET', '/admin?tab=admins');
        self::assertStringNotContainsString('data-admin-tab="admins"', (string) $this->client->getResponse()->getContent());
    }

    // ------------------------------------------------------------------ Confirmation par mot de passe

    public function testSensitiveActionsRequireThePasswordAgain(): void
    {
        $this->superAdmin();

        foreach ([null, '', 'mauvais-mot-de-passe'] as $wrong) {
            $r = $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['password' => $wrong ?? '']));
            self::assertSame(403, $this->httpStatus());
            self::assertSame('step_up_failed', $r['error']);
        }
        self::assertSame(0, $this->em()->getRepository(AdminProfile::class)->count([]) - 1, 'Aucun administrateur n\'a été créé (seul le Super Admin existe).');
        self::assertSame(3, $this->em()->getRepository(AuditLog::class)->count(['action' => 'auth.step_up.failed']));
    }

    public function testRepeatedWrongPasswordsLockTheAccountEvenForTheRightPassword(): void
    {
        $this->superAdmin();

        for ($i = 0; $i < 5; ++$i) {
            $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['password' => 'faux-'.$i]));
            self::assertSame(403, $this->httpStatus());
        }
        $r = $this->api('POST', '/admin/api/administrateurs', $this->inviteBody());
        self::assertSame(429, $this->httpStatus());
        self::assertSame('step_up_locked', $r['error']);
    }

    public function testACorrectPasswordResetsTheFailureCounter(): void
    {
        $this->superAdmin();

        // 4 erreurs, une réussite, 4 erreurs, une réussite : le compteur repart de zéro à chaque bon mot de passe.
        for ($round = 0; $round < 2; ++$round) {
            for ($i = 0; $i < 4; ++$i) {
                $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['password' => 'faux-'.$i]));
                self::assertSame(403, $this->httpStatus());
            }
            $this->api('POST', '/admin/api/administrateurs', $this->inviteBody(['email' => 'admin'.$round.'@example.com']));
            self::assertSame(201, $this->httpStatus(), 'Tour '.$round);
        }
    }

    // ------------------------------------------------------------------ Modifier / suspendre

    public function testUpdateAccessChangesProfileAndOverrides(): void
    {
        $this->superAdmin();
        $id = $this->invite();

        $this->api('POST', '/admin/api/administrateurs/'.$id.'/acces', [
            'roleId' => $this->roleId('moderation'),
            'extra' => ['finance.view', 'admins.manage'],
            'denied' => ['suppliers.view'],
            'password' => self::PASSWORD,
        ]);

        self::assertSame(200, $this->httpStatus());
        $profile = $this->em()->getRepository(AdminProfile::class)->findOneBy(['user' => $this->adminByEmail('nouveau@example.com')]);
        self::assertSame('moderation', $profile->getRole()->getCode());
        self::assertSame(['finance.view'], $profile->getExtraPermissions(), '« admins.manage » est écarté : il n\'est pas attribuable.');
        self::assertSame(['suppliers.approve', 'finance.view'], $profile->effectivePermissions());
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.updated']));
    }

    public function testSuspendedAdminLosesHisOpenSessionAndCannotSignIn(): void
    {
        $this->superAdmin();
        $target = $this->createAdminUser('cible@example.com', 'support');

        $r = $this->api('POST', '/admin/api/administrateurs/'.$target->getId()->toRfc4122().'/statut', ['action' => 'suspend', 'password' => self::PASSWORD]);
        self::assertSame('suspended', $r['status']);

        $this->newBrowser();
        $r = $this->api('POST', '/auth/login', ['email' => 'cible@example.com', 'password' => self::PASSWORD]);
        self::assertSame('blocked', $r['step'] ?? null);

        // Réactivé, il peut se reconnecter.
        $this->newBrowser();
        $this->signInAdmin($this->adminByEmail('root@example.com'));
        $this->api('POST', '/admin/api/administrateurs/'.$target->getId()->toRfc4122().'/statut', ['action' => 'reactivate', 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        $this->newBrowser();
        $this->signInAdmin($target);
    }

    public function testOpenSessionOfAnAdminEndsWhenHisAccountIsSuspended(): void
    {
        $admin = $this->createAdminUser('cible@example.com', 'support');
        $this->signInAdmin($admin);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $user = $this->adminByEmail('cible@example.com');
        $user->suspend();
        $this->em()->flush();

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects();
    }

    public function testSuperAdminProtectionRules(): void
    {
        $root = $this->superAdmin();

        $r = $this->api('POST', '/admin/api/administrateurs/'.$root->getId()->toRfc4122().'/statut', ['action' => 'suspend', 'password' => self::PASSWORD]);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('cannot_modify_super_admin', $r['error'], 'Le Super Admin ne se gère pas depuis l\'interface (ni lui-même).');

        $r = $this->api('POST', '/admin/api/administrateurs/'.$root->getId()->toRfc4122().'/acces', ['roleId' => $this->roleId('support'), 'password' => self::PASSWORD]);
        self::assertSame('cannot_modify_super_admin', $r['error']);
        self::assertTrue($this->adminByEmail('root@example.com')->isSuperAdmin());
    }

    public function testCustomersAreNotManagedThroughTheAdminEndpoints(): void
    {
        $this->superAdmin();
        $client = $this->createUser('client@example.com');

        $this->api('POST', '/admin/api/administrateurs/'.$client->getId()->toRfc4122().'/statut', ['action' => 'suspend', 'password' => self::PASSWORD]);

        self::assertSame(404, $this->httpStatus());
        self::assertSame(AccountStatus::Active, $this->reload($client)->getStatus());
    }

    // ------------------------------------------------------------------ Profils de droits

    public function testCreateEditAndDeleteAProfile(): void
    {
        $this->superAdmin();

        $r = $this->api('POST', '/admin/api/profils', [
            'code' => 'logistique',
            'label' => 'Admin Logistique',
            'description' => 'Suivi des colis',
            'permissions' => ['orders.view', 'orders.update_tracking', 'admins.manage'],
            'password' => self::PASSWORD,
        ]);
        self::assertSame(200, $this->httpStatus(), json_encode($r));
        $role = $this->em()->getRepository(AdminRole::class)->findOneBy(['code' => 'logistique']);
        self::assertSame(['orders.view', 'orders.update_tracking'], $role->getPermissions(), 'Aucun profil « normal » ne peut contenir admins.manage.');

        // Modification (par identifiant).
        $this->api('POST', '/admin/api/profils', ['id' => $r['id'], 'code' => 'logistique', 'label' => 'Logistique & livraisons', 'permissions' => ['orders.view'], 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        $role = $this->em()->getRepository(AdminRole::class)->findOneBy(['code' => 'logistique']);
        self::assertSame('Logistique & livraisons', $role->getLabel());
        self::assertSame(['orders.view'], $role->getPermissions());

        // Suppression : possible tant qu'aucun administrateur ne l'utilise.
        $this->api('POST', '/admin/api/profils/'.$r['id'].'/supprimer', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertNull($this->em()->getRepository(AdminRole::class)->findOneBy(['code' => 'logistique']));
    }

    public function testProfileValidation(): void
    {
        $this->superAdmin();
        $base = ['label' => 'Test', 'permissions' => [], 'password' => self::PASSWORD];

        $r = $this->api('POST', '/admin/api/profils', ['code' => 'Code Invalide'] + $base);
        self::assertSame('invalid_code', $r['error']);
        $r = $this->api('POST', '/admin/api/profils', ['code' => 'finance'] + $base);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('code_taken', $r['error']);
        $r = $this->api('POST', '/admin/api/profils', ['label' => 'X', 'code' => 'abc', 'permissions' => [], 'password' => self::PASSWORD]);
        self::assertSame('invalid_label', $r['error']);
        $r = $this->api('POST', '/admin/api/profils', ['code' => 'abc', 'permissions' => ['nimporte.quoi']] + $base);
        self::assertSame('invalid_permission', $r['error']);
        self::assertNull($this->em()->getRepository(AdminRole::class)->findOneBy(['code' => 'abc']));
    }

    public function testProfileInUseOrSystemProfileCannotBeDeleted(): void
    {
        $this->superAdmin();
        $r = $this->api('POST', '/admin/api/profils', ['code' => 'temporaire', 'label' => 'Temporaire', 'permissions' => ['orders.view'], 'password' => self::PASSWORD]);
        $this->invite(['roleId' => $r['id']]);

        $e = $this->api('POST', '/admin/api/profils/'.$r['id'].'/supprimer', ['password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('role_in_use', $e['error']);

        $e = $this->api('POST', '/admin/api/profils/'.$this->roleId('super_admin').'/supprimer', ['password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('system_role', $e['error']);
    }

    public function testProfilesAreManagedByTheSuperAdminOnly(): void
    {
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));

        $this->api('POST', '/admin/api/profils', ['code' => 'pirate', 'label' => 'Pirate', 'permissions' => ['users.view'], 'password' => self::PASSWORD]);

        self::assertSame(403, $this->httpStatus());
        self::assertNull($this->em()->getRepository(AdminRole::class)->findOneBy(['code' => 'pirate']));
    }

    // ------------------------------------------------------------------ Exports CSV

    /** Jeton CSRF du formulaire d'export, lu comme le fait le navigateur : dans le champ caché de la page. */
    private function exportToken(string $type = 'clients'): string
    {
        $crawler = $this->client->request('GET', '/admin?tab=exports');
        $this->em()->clear();
        $field = $crawler->filter('form[action="/admin/export/'.$type.'"] input[name="_token"]');
        self::assertCount(1, $field, 'Formulaire d\'export « '.$type.' » absent de la page.');

        return (string) $field->attr('value');
    }

    private function download(string $type, ?string $token = null): string
    {
        $this->client->request('POST', '/admin/export/'.$type, ['_token' => $token ?? $this->exportToken($type)]);
        $this->em()->clear();
        self::assertResponseIsSuccessful();

        return (string) $this->client->getInternalResponse()->getContent();
    }

    public function testClientExportIsAWellFormedCsvWithoutSecrets(): void
    {
        $this->createUser('client@example.com', phone: '+261341234567', phoneVerified: true);
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));

        $csv = $this->download('clients');

        $response = $this->client->getResponse();
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="gobuy_clients_\d{4}-\d{2}-\d{2}\.csv"$/', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM UTF-8 : Excel affiche correctement les accents.');
        self::assertStringContainsString('ID;"Nom complet";E-mail', $csv);
        self::assertStringContainsString('client@example.com', $csv);
        self::assertStringContainsString('034 12 345 67', $csv, 'Téléphone lisible, sans « + » en tête.');
        // Jamais de secret : ni empreinte de mot de passe, ni code.
        self::assertStringNotContainsString('$2y$', $csv);
        self::assertStringNotContainsString('$argon', $csv);
        self::assertStringNotContainsString('password', mb_strtolower($csv));
    }

    public function testExportIsRecordedInTheAuditLog(): void
    {
        $this->createUser('un@example.com');
        $this->createUser('deux@example.com');
        $admin = $this->createAdminUser('compta@example.com', 'compta');
        $this->signInAdmin($admin);

        $this->download('clients');

        $log = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'export.csv']);
        self::assertNotNull($log);
        self::assertSame('compta@example.com', $log->getActorEmail());
        self::assertSame('clients', $log->getTargetId());
        self::assertSame(2, $log->getMetadata()['rows']);
    }

    public function testSpreadsheetFormulasInUserDataAreNeutralised(): void
    {
        foreach (['=HYPERLINK("http://evil.example","clic")', '+1+1', '-2+3', '@SUM(A1)'] as $i => $name) {
            $user = User::registerClient('piege'.$i.'@example.com', $name, null, 'fr', new \DateTimeImmutable());
            $user->setPasswordHash('x', new \DateTimeImmutable());
            $this->em()->persist($user);
        }
        $this->em()->flush();
        $this->em()->clear();
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));

        $csv = $this->download('clients');

        // Chaque cellule dangereuse commence par une apostrophe : Excel l'affiche comme du texte, sans l'exécuter.
        self::assertStringContainsString('"\'=HYPERLINK(""http://evil.example"",""clic"")"', $csv);
        self::assertStringContainsString("'+1+1", $csv);
        self::assertStringContainsString("'-2+3", $csv);
        self::assertStringContainsString("'@SUM(A1)", $csv);
        self::assertStringNotContainsString(';=HYPERLINK', $csv);
        self::assertStringNotContainsString(';"=HYPERLINK', $csv);
        self::assertStringNotContainsString(';+1+1', $csv);
        self::assertStringNotContainsString(';@SUM', $csv);
    }

    public function testExportNeedsTheRightTheTokenAndPost(): void
    {
        $this->createUser('client@example.com');
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));

        // Pas de jeton : refusé, rien n'est écrit dans le journal.
        $this->client->request('POST', '/admin/export/clients');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/export/clients', ['_token' => 'forgé']);
        self::assertResponseStatusCodeSame(403);
        // Un lien (GET) ne déclenche jamais de téléchargement.
        $this->client->request('GET', '/admin/export/clients');
        self::assertResponseStatusCodeSame(405);
        // Type d'export inconnu.
        $this->client->request('POST', '/admin/export/tout', ['_token' => $this->exportToken()]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, $this->em()->getRepository(AuditLog::class)->count(['action' => 'export.csv']));
    }

    public function testEachExportFollowsItsOwnRight(): void
    {
        $this->createUser('client@example.com');
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support')); // voit les clients mais ne les exporte pas

        $this->client->request('GET', '/admin?tab=exports');
        $token = (string) $this->client->getCrawler()->filter('input[name="_token"]')->count();
        $this->client->request('POST', '/admin/export/clients', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/export/commandes', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/export/admins', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('0', $token, 'Aucun formulaire d\'export affiché pour ce profil.');
    }

    public function testAdminListExportIsForTheSuperAdminOnly(): void
    {
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));
        $this->client->request('POST', '/admin/export/admins', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403, 'Même un profil avec beaucoup de droits ne voit pas la liste des administrateurs.');

        $this->newBrowser();
        $this->signInAdmin($this->createSuperAdmin());
        $csv = $this->download('admins');
        self::assertStringContainsString('compta@example.com', $csv);
        self::assertStringContainsString('root@example.com', $csv);
        self::assertStringContainsString('finance.view', $csv);
    }

    public function testSupplierAndOrderExports(): void
    {
        $user = $this->createUser('boutique@example.com', AccountType::Supplier, true, '+261331112233', true);
        $this->em()->persist(new SupplierProfile($this->reload($user), '=Boutique piégée', 'Lot 12', null, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->em()->clear();
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));

        $csv = $this->download('fournisseurs');
        self::assertStringContainsString('boutique@example.com', $csv);
        self::assertStringContainsString("'=Boutique piégée", $csv);
        self::assertStringContainsString('033 11 122 33', $csv);

        $csv = $this->download('commandes');
        self::assertStringContainsString('N° commande', $csv);
    }

    public function testExportQuota(): void
    {
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));
        $token = $this->exportToken();

        for ($i = 0; $i < 20; ++$i) {
            $this->client->request('POST', '/admin/export/clients', ['_token' => $token]);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('POST', '/admin/export/clients', ['_token' => $token]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('too_many_exports', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);
    }

    // ------------------------------------------------------------------ Commandes console du Super Admin

    private function runCommand(string $name, array $input, array $answers = []): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find($name));
        $tester->setInputs($answers);
        $tester->execute($input, ['interactive' => true]);

        return $tester;
    }

    public function testSuperAdminIsCreatedByTheConsoleCommandOnlyOnce(): void
    {
        putenv('GOBUY_SUPER_ADMIN_PASSWORD='.self::ADMIN_PASSWORD);
        try {
            $tester = $this->runCommand('app:admin:create-super', ['--email' => 'root@example.com', '--name' => 'Root Admin']);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $root = $this->adminByEmail('root@example.com');
            self::assertTrue($root->isSuperAdmin());
            self::assertTrue($root->isEmailVerified());

            $tester = $this->runCommand('app:admin:create-super', ['--email' => 'autre@example.com', '--name' => 'Autre']);
            self::assertSame(1, $tester->getStatusCode());
            self::assertStringContainsString('existe déjà', $tester->getDisplay());
        } finally {
            putenv('GOBUY_SUPER_ADMIN_PASSWORD');
        }

        // Le Super Admin se connecte avec ce mot de passe (+ code e-mail).
        $this->signInAdmin($this->adminByEmail('root@example.com'), self::ADMIN_PASSWORD);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    public function testSuperAdminCommandRefusesAWeakPassword(): void
    {
        putenv('GOBUY_SUPER_ADMIN_PASSWORD=trop-court');
        try {
            $tester = $this->runCommand('app:admin:create-super', ['--email' => 'root@example.com', '--name' => 'Root Admin']);
        } finally {
            putenv('GOBUY_SUPER_ADMIN_PASSWORD');
        }

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['accountType' => AccountType::Admin]));
    }

    public function testResetSuperAdminPasswordLogsEverySessionOut(): void
    {
        $root = $this->createSuperAdmin();
        $this->signInAdmin($root);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $new = 'Nouveau!Mot-de-passe-Fort-77';
        $tester = $this->runCommand('app:admin:reset-super-password', ['--email' => 'root@example.com'], [$new, $new]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        // La session ouverte avant le changement est coupée…
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects();
        // … l'ancien mot de passe ne marche plus, le nouveau oui.
        $this->newBrowser();
        $r = $this->api('POST', '/auth/login', ['email' => 'root@example.com', 'password' => self::PASSWORD]);
        self::assertSame('login', $r['step'] ?? null);
        $this->signInAdmin($root, $new);
    }

    public function testResetCommandOnlyConcernsTheSuperAdmin(): void
    {
        $this->createAdminUser('simple@example.com', 'support');
        $new = 'Nouveau!Mot-de-passe-Fort-77';

        $tester = $this->runCommand('app:admin:reset-super-password', ['--email' => 'simple@example.com'], [$new, $new]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Aucun Super Admin', $tester->getDisplay());
    }
}
