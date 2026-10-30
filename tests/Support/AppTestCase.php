<?php

namespace App\Tests\Support;

use App\Admin\AdminAccountService;
use App\Entity\AdminProfile;
use App\Entity\AdminRole;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\OtpPurpose;
use App\Enum\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Socle des tests fonctionnels : un navigateur simulé, une base vidée avant chaque test et des aides
 * pour appeler l'API JSON comme le fait le JavaScript du site (cookie de session + jeton CSRF).
 */
abstract class AppTestCase extends WebTestCase
{
    use ClockSensitiveTrait;

    protected KernelBrowser $client;
    private ?string $csrf = null;

    /** Mot de passe valide utilisé par les comptes de test. */
    protected const PASSWORD = 'Un-mot-de-passe-solide-2026';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Garde le MÊME conteneur entre les requêtes d'un test : la livraison OTP en mémoire reste lisible.
        $this->client->disableReboot();
        $this->truncate();
        // Compteurs du rate-limiter remis à zéro : un test ne doit jamais hériter des tentatives du précédent.
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->csrf = null;
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function otp(): InMemoryOtpDelivery
    {
        return static::getContainer()->get(InMemoryOtpDelivery::class);
    }

    /** Vide toutes les tables sauf le référentiel des rôles et l'historique des migrations. */
    private function truncate(): void
    {
        $connection = $this->em()->getConnection();
        $tables = $connection->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename NOT IN ('admin_roles', 'doctrine_migration_versions')");
        if ([] !== $tables) {
            $connection->executeStatement('TRUNCATE TABLE '.implode(', ', array_map(static fn (string $t): string => '"'.$t.'"', $tables)).' RESTART IDENTITY CASCADE');
        }
        // Les profils de droits créés par un test disparaissent ; ceux fournis par les migrations (Finance, Compta…) restent.
        $connection->executeStatement("DELETE FROM admin_roles WHERE code NOT IN ('super_admin', 'finance', 'compta', 'support', 'moderation')");
        $this->em()->clear();
    }

    /**
     * Appelle l'API JSON comme le navigateur : cookie de session conservé, jeton CSRF dans l'en-tête.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> corps JSON de la réponse (le code HTTP : $this->client->getResponse()->getStatusCode())
     */
    protected function api(string $method, string $uri, array $data = [], bool $withCsrf = true, string $ip = '203.0.113.7'): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $ip];
        if ($withCsrf) {
            $server['HTTP_X_CSRF_TOKEN'] = $this->csrfToken();
        }
        $this->client->request($method, $uri, [], [], $server, 'GET' === $method ? null : json_encode($data, \JSON_THROW_ON_ERROR));
        $this->em()->clear(); // comme une vraie nouvelle requête : rien n'est gardé en mémoire d'une requête à l'autre

        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : (array) json_decode($content, true);
    }

    protected function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * Oublie le jeton CSRF mémorisé. À appeler après une connexion : Symfony efface les jetons CSRF de la session
     * à la connexion (protection contre la « fixation de jeton »), le navigateur recharge donc la page pour en obtenir un neuf.
     */
    protected function refreshCsrf(): void
    {
        $this->csrf = null;
    }

    /**
     * Récupère le jeton CSRF comme le fait le site : dans la balise <meta name="csrf-token"> d'une page.
     * On lit l'ACCUEIL (public, et affiché aussi aux personnes connectées : /connexion les redirige vers leur espace).
     */
    protected function csrfToken(): string
    {
        if (null === $this->csrf) {
            $crawler = $this->client->request('GET', '/');
            $this->csrf = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
            $this->em()->clear();
        }

        return $this->csrf;
    }

    /** Crée un compte directement en base (sans passer par l'inscription). */
    protected function createUser(
        string $email = 'client@example.com',
        AccountType $type = AccountType::Client,
        bool $emailVerified = true,
        ?string $phone = null,
        bool $phoneVerified = false,
        string $password = self::PASSWORD,
    ): User {
        $now = new \DateTimeImmutable();
        $user = match ($type) {
            AccountType::Client => User::registerClient($email, 'Test Client', $phone, 'fr', $now),
            AccountType::Supplier => User::registerSupplier($email, 'Test Fournisseur', $phone ?? '+261341234567', 'fr', $now),
            AccountType::Admin => User::createAdmin($email, 'Test Admin', 'fr', false, $now),
        };
        $user->setPasswordHash(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password), $now);
        if ($emailVerified) {
            $user->markEmailVerified($now);
        }
        if ($phoneVerified) {
            $user->markPhoneVerified($now);
        }
        $this->em()->persist($user);
        $this->em()->flush();
        $this->em()->clear();

        return $user;
    }

    /**
     * Connecte le navigateur de test à ce compte, comme un vrai utilisateur : e-mail + mot de passe via l'API.
     * (Le compte doit avoir son e-mail vérifié et pas de 2FA : la connexion se termine alors en une étape.)
     */
    protected function signIn(User $user, string $password = self::PASSWORD): void
    {
        $r = $this->api('POST', '/auth/login', ['email' => $user->getEmail(), 'password' => $password]);
        self::assertSame('done', $r['step'] ?? null, 'La connexion de test aurait dû aboutir.');
        // Symfony efface les jetons CSRF à la connexion : le navigateur recharge une page pour en obtenir un neuf.
        $this->refreshCsrf();
    }

    /**
     * Crée un administrateur « normal » avec un profil de droits fourni par les migrations
     * (finance, compta, support, moderation) et, si besoin, des droits ajoutés ou retirés.
     *
     * @param list<Permission> $extra
     * @param list<Permission> $denied
     */
    protected function createAdminUser(string $email = 'admin@example.com', string $roleCode = 'finance', array $extra = [], array $denied = []): User
    {
        $now = new \DateTimeImmutable();
        $role = $this->em()->getRepository(AdminRole::class)->findOneBy(['code' => $roleCode]) ?? throw new \LogicException('Profil inconnu : '.$roleCode);
        $user = User::createAdmin($email, 'Admin '.$roleCode, 'fr', false, $now);
        $user->setPasswordHash(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD), $now);
        $user->markEmailVerified($now);
        $this->em()->persist($user);
        $this->em()->persist(new AdminProfile($user, $role, $extra, $denied, null, $now));
        $this->em()->flush();
        $this->em()->clear();

        return $user;
    }

    /** Crée le Super Admin par le même service que la commande console `app:admin:create-super`. */
    protected function createSuperAdmin(string $email = 'root@example.com'): User
    {
        $user = static::getContainer()->get(AdminAccountService::class)->createSuperAdmin($email, 'Root Admin', self::PASSWORD, 'fr');
        $this->em()->clear();

        return $user;
    }

    /**
     * Connexion complète d'un administrateur : mot de passe PUIS code reçu par e-mail (la 2FA est toujours exigée pour eux).
     */
    protected function signInAdmin(User $admin, string $password = self::PASSWORD): void
    {
        // Deux connexions d'un même compte à moins de 30 s : le 2e code serait refusé (délai anti-spam entre deux envois).
        // On avance l'horloge de test d'une minute pour reproduire une vraie reconnexion.
        static::mockTime(Clock::get()->now()->modify('+1 minute'));
        $r = $this->api('POST', '/auth/login', ['email' => $admin->getEmail(), 'password' => $password]);
        self::assertSame('two_factor', $r['step'] ?? null, 'Un administrateur doit passer par la double authentification.');
        $r = $this->api('POST', '/auth/verify', ['code' => $this->otp()->lastCode(OtpPurpose::TwoFactor)]);
        self::assertSame('done', $r['step'] ?? null, 'La connexion de test de l\'administrateur aurait dû aboutir.');
        $this->refreshCsrf();
    }

    /** Simule un autre navigateur : plus aucun cookie, plus de jeton CSRF mémorisé. */
    protected function newBrowser(): void
    {
        $this->client->getCookieJar()->clear();
        $this->refreshCsrf();
    }

    /** Recharge un utilisateur depuis la base (état frais). */
    protected function reload(User $user): User
    {
        $this->em()->clear();

        return $this->em()->getRepository(User::class)->find($user->getId()) ?? throw new \LogicException('Utilisateur introuvable.');
    }
}
