<?php

namespace App\Tests\Auth;

use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\AccountType;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Enum\SupplierStatus;
use App\Tests\Support\AppTestCase;

/**
 * Parcours complets de connexion / inscription, testés comme le fait le navigateur (API JSON + session).
 */
final class AuthFlowTest extends AppTestCase
{
    private const REGISTER_CLIENT = [
        'fullName' => 'Rakoto Jean',
        'email' => 'jean@example.com',
        'password' => self::PASSWORD,
        'phone' => '',
    ];

    // ------------------------------------------------------------------ Inscription client

    public function testClientRegistrationWithEmailVerificationLogsIn(): void
    {
        $r = $this->api('POST', '/auth/register/client', self::REGISTER_CLIENT);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('verify_email', $r['step']);
        self::assertSame('j•••@example.com', $r['maskedTarget']);
        self::assertSame(1, $this->otp()->count(OtpPurpose::EmailVerification));

        // Pas encore connecté : une page protégée renvoie vers la connexion.
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects();

        // Le code reçu par e-mail valide le compte ET termine la connexion (aucun téléphone saisi → pas d'étape SMS).
        $r = $this->api('POST', '/auth/verify', ['code' => $this->otp()->lastCode(OtpPurpose::EmailVerification)]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('done', $r['step']);

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jean@example.com']);
        self::assertNotNull($user);
        self::assertTrue($user->isEmailVerified());
        self::assertTrue($user->isActive());
        self::assertSame(AccountType::Client, $user->getAccountType());
        // Le mot de passe est stocké haché, jamais en clair.
        self::assertStringStartsWith('$argon2id$', (string) $user->getPassword());

        $this->client->request('GET', '/compte');
        self::assertResponseIsSuccessful();
    }

    public function testRegistrationWithPhoneOffersOptionalSmsStep(): void
    {
        $this->api('POST', '/auth/register/client', ['phone' => '034 12 345 67'] + self::REGISTER_CLIENT);
        $r = $this->api('POST', '/auth/verify', ['code' => $this->otp()->lastCode(OtpPurpose::EmailVerification)]);

        // Après l'e-mail, le SMS est PROPOSÉ (facultatif) car un numéro a été saisi.
        self::assertSame('verify_sms', $r['step']);
        self::assertTrue($r['skippable']);
        self::assertSame('034 •• ••• 67', $r['maskedTarget']);
        self::assertSame(OtpChannel::Sms->value, $r['channel']);

        $r = $this->api('POST', '/auth/skip');
        self::assertSame('done', $r['step']);

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jean@example.com']);
        self::assertSame('+261341234567', $user->getPhone());
        self::assertFalse($user->isPhoneVerified());
    }

    public function testSmsVerificationMarksPhoneVerified(): void
    {
        $this->api('POST', '/auth/register/client', ['phone' => '0341234567'] + self::REGISTER_CLIENT);
        $this->api('POST', '/auth/verify', ['code' => $this->otp()->lastCode(OtpPurpose::EmailVerification)]);
        $r = $this->api('POST', '/auth/verify', ['code' => $this->otp()->lastCode(OtpPurpose::PhoneVerification, OtpChannel::Sms)]);

        self::assertSame('done', $r['step']);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jean@example.com']);
        self::assertTrue($user->isPhoneVerified());
    }

    public function testRegistrationValidationErrors(): void
    {
        $r = $this->api('POST', '/auth/register/client', ['fullName' => '', 'email' => 'pas-un-email', 'password' => 'court', 'phone' => '12'] );

        self::assertSame(422, $this->httpStatus());
        self::assertSame('validation', $r['error']);
        foreach (['fullName', 'email', 'password', 'phone'] as $field) {
            self::assertArrayHasKey($field, $r['errors'], "Erreur attendue sur « $field »");
        }
        self::assertSame(0, $this->otp()->count());
    }

    public function testPasswordContainingEmailLocalPartIsRejected(): void
    {
        $r = $this->api('POST', '/auth/register/client', ['password' => 'jean-super-2026!'] + self::REGISTER_CLIENT);

        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('password', $r['errors']);
    }

    // ------------------------------------------------------------------ Anti-énumération

    public function testRegisteringExistingEmailLooksIdenticalAndWarnsOwner(): void
    {
        $this->createUser('jean@example.com');

        $r = $this->api('POST', '/auth/register/client', self::REGISTER_CLIENT);

        // Même écran qu'une vraie inscription…
        self::assertSame(200, $this->httpStatus());
        self::assertSame('verify_email', $r['step']);
        self::assertSame('j•••@example.com', $r['maskedTarget']);
        // …mais aucun code n'a été émis ; le titulaire reçoit un e-mail d'information.
        self::assertSame(0, $this->otp()->count());
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'To', 'Test Client <jean@example.com>');

        // Toute saisie échoue, et le compte est bloqué après 5 essais comme pour de vrai.
        for ($i = 4; $i >= 0; --$i) {
            $r = $this->api('POST', '/auth/verify', ['code' => '000000']);
            self::assertSame(422, $this->httpStatus());
        }
        self::assertSame('code_locked', $r['error']);
    }

    /**
     * Le leurre (adresse déjà inscrite) doit répondre avec les MÊMES champs qu'une vraie inscription à chaque étape :
     * inscription, rechargement de la page, saisie d'un mauvais code, renvoi trop rapide. Sinon un champ en plus ou en
     * moins (par exemple « user ») suffirait à savoir si une adresse est inscrite.
     */
    public function testDecoyAnswersHaveTheSameShapeAsRealOnesAtEveryStep(): void
    {
        $this->createUser('jean@example.com');

        $run = function (string $email): array {
            $this->newBrowser();
            $answers = [];
            $answers['register'] = $this->api('POST', '/auth/register/client', ['email' => $email] + self::REGISTER_CLIENT);
            $answers['state'] = $this->api('GET', '/auth/state');
            $answers['wrong_code'] = $this->api('POST', '/auth/verify', ['code' => '000000']);
            $answers['resend_too_soon'] = $this->api('POST', '/auth/resend');

            return $answers;
        };
        $real = $run('neuf@example.com');
        $decoy = $run('jean@example.com');

        foreach ($real as $step => $answer) {
            self::assertSame(array_keys($answer), array_keys($decoy[$step]), $step.' : mêmes champs');
            self::assertSame($answer['step'], $decoy[$step]['step'], $step);
            self::assertSame($answer['error'], $decoy[$step]['error'], $step);
            self::assertSame($answer['channel'], $decoy[$step]['channel'], $step);
            self::assertSame($answer['channels'], $decoy[$step]['channels'], $step);
            self::assertSame($answer['skippable'], $decoy[$step]['skippable'], $step);
            self::assertSame($answer['attemptsLeft'], $decoy[$step]['attemptsLeft'], $step);
            // « user » existe dans les deux cas et reprend ce que le visiteur a saisi (jamais les données du vrai compte).
            self::assertSame(['name', 'email'], array_keys($decoy[$step]['user']), $step.' : champ user');
            self::assertSame('Rakoto Jean', $decoy[$step]['user']['name'], $step);
            // Les compteurs d'attente ne diffèrent que d'une seconde au plus (deux requêtes à des instants voisins).
            self::assertEqualsWithDelta($answer['resendIn'], $decoy[$step]['resendIn'], 2, $step.' : resendIn');
            self::assertEqualsWithDelta($answer['retryAfter'], $decoy[$step]['retryAfter'], 2, $step.' : retryAfter');
        }
        self::assertSame('too_soon', $decoy['resend_too_soon']['error']);
        self::assertSame('jean@example.com', $decoy['register']['user']['email']);
    }

    public function testUnknownEmailAndWrongPasswordGiveIdenticalAnswers(): void
    {
        $this->createUser('jean@example.com');

        $wrong = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => 'mauvais-mot-de-passe']);
        $wrongStatus = $this->httpStatus();
        $unknown = $this->api('POST', '/auth/login', ['email' => 'inconnu@example.com', 'password' => 'mauvais-mot-de-passe']);

        self::assertSame(401, $wrongStatus);
        self::assertSame($wrongStatus, $this->httpStatus());
        self::assertSame($wrong['error'], $unknown['error']);
        self::assertSame($wrong['message'], $unknown['message']);
    }

    public function testOversizedLoginEmailIsCappedAndAnsweredLikeAnUnknownAccount(): void
    {
        $this->createUser('jean@example.com');

        // 5 000 caractères : jamais recopiés tels quels dans le limiteur ni dans le journal (colonne de 180 caractères).
        $giant = str_repeat('a', 5000).'@example.com';
        $r = $this->api('POST', '/auth/login', ['email' => $giant, 'password' => 'mauvais-mot-de-passe']);
        $unknown = $this->api('POST', '/auth/login', ['email' => 'inconnu@example.com', 'password' => 'mauvais-mot-de-passe']);

        self::assertSame(401, $this->httpStatus());
        self::assertSame($unknown['error'], $r['error']);
        foreach ($this->em()->getRepository(\App\Entity\AuditLog::class)->findAll() as $line) {
            self::assertLessThanOrEqual(180, mb_strlen((string) $line->getActorEmail()));
        }

        // Une adresse tronquée à 180 caractères ne retrouve jamais un compte réel : le préfixe d'un e-mail existant ne suffit pas.
        $prefix = 'jean@example.com'.str_repeat('x', 400);
        $this->api('POST', '/auth/login', ['email' => $prefix, 'password' => self::PASSWORD]);
        self::assertSame(401, $this->httpStatus());
    }

    // ------------------------------------------------------------------ Connexion

    public function testPasswordLoginSucceeds(): void
    {
        $this->createUser('jean@example.com');

        $r = $this->api('POST', '/auth/login', ['email' => ' Jean@Example.com ', 'password' => self::PASSWORD]);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('done', $r['step']);
        self::assertSame('/', $r['redirect']);
        $this->client->request('GET', '/compte');
        self::assertResponseIsSuccessful();
    }

    public function testLoginBlockedAfterFiveFailuresEvenWithGoodPassword(): void
    {
        $this->createUser('jean@example.com');

        for ($i = 0; $i < 5; ++$i) {
            $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => 'faux-faux-faux'], ip: '203.0.113.'.(10 + $i));
            self::assertSame(401, $this->httpStatus());
        }

        // Même avec le BON mot de passe et une autre IP : le compte est temporairement verrouillé.
        $r = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD], ip: '198.51.100.1');
        self::assertSame(429, $this->httpStatus());
        self::assertSame('too_many_attempts', $r['error']);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    public function testUnverifiedClientMustVerifyEmailBeforeEntering(): void
    {
        $this->createUser('jean@example.com', emailVerified: false);

        $r = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);

        self::assertSame('verify_email', $r['step']);
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects(); // toujours pas connecté
    }

    public function testClientWithVerifiedPhoneOnlyCanEnter(): void
    {
        // Règle : e-mail OU téléphone vérifié suffit pour un client.
        $this->createUser('jean@example.com', emailVerified: false, phone: '+261341234567', phoneVerified: true);

        $r = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);

        self::assertSame('done', $r['step']);
    }

    public function testSuspendedAccountIsRefused(): void
    {
        $user = $this->createUser('jean@example.com');
        $user = $this->reload($user);
        $user->suspend();
        $this->em()->flush();

        $r = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);

        self::assertSame(403, $this->httpStatus());
        self::assertSame('blocked', $r['step']);
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects();
    }

    public function testStepCannotBeSkippedByCallingLaterEndpoints(): void
    {
        // Un client non vérifié ne peut pas « sauter » l'étape en appelant /skip ou /restore directement.
        $this->createUser('jean@example.com', emailVerified: false);
        $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);

        $r = $this->api('POST', '/auth/skip');
        self::assertSame('cannot_skip', $r['error']);
        $r = $this->api('POST', '/auth/restore');
        self::assertSame('unexpected_step', $r['error']);
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects();
    }

    // ------------------------------------------------------------------ Codes

    public function testWrongCodeCountsDownThenLocks(): void
    {
        $this->createUser('jean@example.com', emailVerified: false);
        $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);
        $good = $this->otp()->lastCode(OtpPurpose::EmailVerification);
        $bad = '000000' === $good ? '111111' : '000000';

        $r = $this->api('POST', '/auth/verify', ['code' => $bad]);
        self::assertSame('invalid_code', $r['error']);
        self::assertSame(4, $r['attemptsLeft']);

        for ($i = 0; $i < 4; ++$i) {
            $r = $this->api('POST', '/auth/verify', ['code' => $bad]);
        }
        self::assertSame('code_locked', $r['error']);

        // Le bon code ne marche plus une fois bloqué.
        $r = $this->api('POST', '/auth/verify', ['code' => $good]);
        self::assertNotSame('done', $r['step']);
        self::assertFalse($r['ok']);
    }

    public function testResendHasCooldown(): void
    {
        $this->createUser('jean@example.com', emailVerified: false);
        $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);

        $r = $this->api('POST', '/auth/resend');
        self::assertSame(429, $this->httpStatus());
        self::assertSame('too_soon', $r['error']);
        self::assertSame(1, $this->otp()->count(OtpPurpose::EmailVerification));
    }

    public function testDeliveryFailureIsReportedWithoutLeakingDetails(): void
    {
        $this->createUser('jean@example.com', emailVerified: false);
        $this->otp()->fail = true;

        $r = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);

        self::assertSame(503, $this->httpStatus());
        self::assertSame('delivery_failed', $r['error']);
        self::assertSame(0, $this->otp()->count());
    }

    // ------------------------------------------------------------------ 2FA

    public function testTwoFactorByEmail(): void
    {
        $user = $this->reload($this->createUser('jean@example.com'));
        $user->setTwoFactor(true, false);
        $this->em()->flush();

        $r = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);
        self::assertSame('two_factor', $r['step']);
        self::assertSame(['email'], $r['channels']);
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects(); // mot de passe correct mais pas encore connecté

        $r = $this->api('POST', '/auth/verify', ['code' => $this->otp()->lastCode(OtpPurpose::TwoFactor)]);
        self::assertSame('done', $r['step']);
    }

    public function testTwoFactorBySmsWhenPhoneVerified(): void
    {
        $user = $this->reload($this->createUser('jean@example.com', phone: '+261341234567', phoneVerified: true));
        $user->setTwoFactor(false, true);
        $this->em()->flush();

        $r = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);

        self::assertSame('two_factor', $r['step']);
        self::assertSame('sms', $r['channel']);
        $r = $this->api('POST', '/auth/verify', ['code' => $this->otp()->lastCode(OtpPurpose::TwoFactor, OtpChannel::Sms)]);
        self::assertSame('done', $r['step']);
    }

    public function testWrongTwoFactorCodeDoesNotLogIn(): void
    {
        $user = $this->reload($this->createUser('jean@example.com'));
        $user->setTwoFactor(true, false);
        $this->em()->flush();
        $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);

        $r = $this->api('POST', '/auth/verify', ['code' => '999999' === $this->otp()->lastCode(OtpPurpose::TwoFactor) ? '888888' : '999999']);

        self::assertSame('invalid_code', $r['error']);
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects();
    }

    // ------------------------------------------------------------------ Fournisseur

    public function testSupplierNeedsEmailAndPhoneThenWaitsForApproval(): void
    {
        $r = $this->api('POST', '/auth/register/supplier', [
            'fullName' => 'Razafy Marie', 'email' => 'marie@boutique.mg', 'password' => self::PASSWORD, 'phone' => '033 12 345 67',
            'companyName' => 'Boutique Marie', 'companyAddress' => 'Lot 12 Analakely, Antananarivo', 'companyDescription' => 'Vêtements',
        ]);
        self::assertSame('verify_email', $r['step']);

        $r = $this->api('POST', '/auth/verify', ['code' => $this->otp()->lastCode(OtpPurpose::EmailVerification)]);
        // Téléphone OBLIGATOIRE pour un fournisseur : l'étape n'est pas ignorable.
        self::assertSame('verify_sms', $r['step']);
        self::assertFalse($r['skippable']);
        self::assertSame('cannot_skip', $this->api('POST', '/auth/skip')['error']);

        $r = $this->api('POST', '/auth/verify', ['code' => $this->otp()->lastCode(OtpPurpose::PhoneVerification)]);
        self::assertSame('done', $r['step']);
        self::assertSame('/fournisseur', $r['redirect']);

        $profile = $this->em()->getRepository(SupplierProfile::class)->findOneBy([]);
        self::assertSame(SupplierStatus::Pending, $profile->getStatus());
        self::assertSame('Boutique Marie', $profile->getCompanyName());
    }

    public function testSupplierApplicationAnswersAreStoredButCleaned(): void
    {
        $r = $this->api('POST', '/auth/register/supplier', [
            'fullName' => 'Razafy Marie', 'email' => 'marie@boutique.mg', 'password' => self::PASSWORD, 'phone' => '033 12 345 67',
            'companyName' => 'Boutique Marie', 'companyAddress' => 'Lot 12 Analakely, Antananarivo', 'companyDescription' => 'Vêtements',
            'application' => [
                'city' => 'Antananarivo', 'activityType' => 'individual', 'mainCategory' => 'cat-mode', 'plan' => 'pro',
                'productTypes' => ['fashion', 'inconnu'], 'ownDelivery' => true, 'damage' => false,
                'expectations' => '<script>alert(1)</script>',
                // Tentatives d'injection : jamais enregistrées.
                'status' => 'approved', 'isAdmin' => true,
            ],
        ]);
        self::assertSame('verify_email', $r['step']);

        $profile = $this->em()->getRepository(SupplierProfile::class)->findOneBy([]);
        self::assertSame(SupplierStatus::Pending, $profile->getStatus(), 'Une candidature reste « en attente » quoi que dise le formulaire.');
        self::assertEquals([
            'city' => 'Antananarivo', 'activityType' => 'individual', 'mainCategory' => 'cat-mode', 'plan' => 'pro',
            'productTypes' => ['fashion'], 'ownDelivery' => true, 'damage' => false,
            'expectations' => '<script>alert(1)</script>', // gardé tel quel en base : c'est l'AFFICHAGE qui échappe (testé côté admin)
        ], $profile->getApplication());
    }

    public function testSupplierApplicationIsOptional(): void
    {
        $this->api('POST', '/auth/register/supplier', [
            'fullName' => 'Razafy Marie', 'email' => 'marie@boutique.mg', 'password' => self::PASSWORD, 'phone' => '033 12 345 67',
            'companyName' => 'Boutique Marie', 'companyAddress' => 'Lot 12 Analakely, Antananarivo', 'application' => 'n\'importe quoi',
        ]);

        self::assertSame(200, $this->httpStatus());
        self::assertNull($this->em()->getRepository(SupplierProfile::class)->findOneBy([])->getApplication());
    }

    public function testSupplierRegistrationRequiresPhone(): void
    {
        $r = $this->api('POST', '/auth/register/supplier', [
            'fullName' => 'Razafy Marie', 'email' => 'marie@boutique.mg', 'password' => self::PASSWORD, 'phone' => '',
            'companyName' => 'Boutique Marie', 'companyAddress' => 'Lot 12 Analakely',
        ]);

        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('phone', $r['errors']);
    }

    // ------------------------------------------------------------------ Compte en cours de suppression

    public function testPendingDeletionAccountMustBeExplicitlyRestored(): void
    {
        $user = $this->reload($this->createUser('jean@example.com'));
        $user->requestDeletion(new \DateTimeImmutable());
        $this->em()->flush();

        $r = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);
        self::assertSame('restore_account', $r['step']);
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects();

        $r = $this->api('POST', '/auth/restore');
        self::assertSame('done', $r['step']);
        $user = $this->reload($user);
        self::assertSame(AccountStatus::Active, $user->getStatus());
        self::assertNull($user->getDeletionScheduledAt());
    }

    // ------------------------------------------------------------------ CSRF

    public function testCsrfTokenIsRequired(): void
    {
        $this->createUser('jean@example.com');

        $r = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD], withCsrf: false);

        self::assertSame(403, $this->httpStatus());
        self::assertSame('csrf', $r['error']);
    }

    public function testForgedCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/auth/login', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'forged'], '{"email":"a@b.c","password":"x"}');

        self::assertSame(403, $this->httpStatus());
    }

    // ------------------------------------------------------------------ Mot de passe oublié

    public function testPasswordResetFullFlow(): void
    {
        $this->createUser('jean@example.com');

        $r = $this->api('POST', '/auth/forgot', ['email' => 'jean@example.com']);
        self::assertSame(200, $this->httpStatus());
        self::assertTrue($r['ok']);
        $code = $this->otp()->lastCode(OtpPurpose::PasswordReset); // envoyé après la réponse (kernel.terminate)

        // Mot de passe faible : refusé SANS consommer le code.
        $r = $this->api('POST', '/auth/reset', ['email' => 'jean@example.com', 'code' => $code, 'password' => 'court']);
        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('password', $r['errors']);

        // Mauvais code.
        $bad = '000000' === $code ? '111111' : '000000';
        $r = $this->api('POST', '/auth/reset', ['email' => 'jean@example.com', 'code' => $bad, 'password' => 'Nouveau-mot-de-passe-2026']);
        self::assertSame('invalid_code', $r['error']);

        // Bon code + bon mot de passe.
        $r = $this->api('POST', '/auth/reset', ['email' => 'jean@example.com', 'code' => $code, 'password' => 'Nouveau-mot-de-passe-2026']);
        self::assertTrue($r['ok']);
        // L'avis de sécurité « mot de passe modifié » est parti dans cette requête.
        self::assertEmailCount(1);

        // L'ancien mot de passe ne marche plus, le nouveau oui.
        $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);
        self::assertSame(401, $this->httpStatus());
        $r = $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => 'Nouveau-mot-de-passe-2026']);
        self::assertSame('done', $r['step']);
    }

    public function testForgotPasswordIsNeutralForUnknownEmail(): void
    {
        $known = $this->createUser('jean@example.com');
        $a = $this->api('POST', '/auth/forgot', ['email' => 'jean@example.com']);
        $statusA = $this->httpStatus();
        $b = $this->api('POST', '/auth/forgot', ['email' => 'inconnu@example.com']);

        self::assertSame($statusA, $this->httpStatus());
        self::assertSame($a, $b);
        self::assertSame(1, $this->otp()->count(OtpPurpose::PasswordReset)); // seul le compte réel a reçu un code
        self::assertNotNull($known);
    }

    public function testResetWithWrongEmailGivesGenericError(): void
    {
        $r = $this->api('POST', '/auth/reset', ['email' => 'inconnu@example.com', 'code' => '123456', 'password' => 'Nouveau-mot-de-passe-2026']);

        self::assertSame('invalid_code', $r['error']);
    }

    public function testNoAdministratorCanUseSelfServiceReset(): void
    {
        // Leur boîte e-mail reçoit déjà leur code de connexion : l'utiliser aussi pour changer le mot de passe donnerait les deux
        // facteurs à qui contrôle la boîte. Le Super Admin doit réinitialiser leur accès (AdminAccountService::resetAccess).
        $admin = $this->createAdminUser('compta@example.com', 'compta');

        $this->api('POST', '/auth/forgot', ['email' => 'compta@example.com']);
        self::assertSame(0, $this->otp()->count(OtpPurpose::PasswordReset));

        // Même à l'étape 2, la réponse ne se distingue pas de celle d'un compte inconnu (pas d'oracle « cette adresse est admin »).
        $short = 'Abcdefghij1!'; // 12 caractères : accepté pour un client, refusé par la politique admin (14)
        $known = $this->api('POST', '/auth/reset', ['email' => 'compta@example.com', 'code' => '123456', 'password' => $short]);
        $knownStatus = $this->httpStatus();
        $unknown = $this->api('POST', '/auth/reset', ['email' => 'inconnu@example.com', 'code' => '123456', 'password' => $short]);

        self::assertSame($knownStatus, $this->httpStatus());
        self::assertSame($unknown, $known);
        self::assertSame('invalid_code', $known['error']);
        self::assertNotNull($admin);
    }

    public function testSuperAdminCannotUseSelfServiceReset(): void
    {
        $now = new \DateTimeImmutable();
        $admin = User::createAdmin('root@example.com', 'Root', 'fr', true, $now);
        $admin->setPasswordHash('x', $now);
        $this->em()->persist($admin);
        $this->em()->flush();
        $this->em()->clear();

        $this->api('POST', '/auth/forgot', ['email' => 'root@example.com']);

        self::assertSame(0, $this->otp()->count(OtpPurpose::PasswordReset));
    }
}
