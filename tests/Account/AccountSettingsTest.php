<?php

namespace App\Tests\Account;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeOidcProvider;

/**
 * Réglages du compte : mot de passe, double authentification, téléphone.
 * Chaque action sensible exige une confirmation (mot de passe, ou code e-mail pour les comptes Google/Apple).
 */
final class AccountSettingsTest extends AppTestCase
{
    private const NEW_PASSWORD = 'Plume-Verte-Horizon-8472';

    private function signedInClient(string $email = 'c@example.com', ?string $phone = null, bool $phoneVerified = false): User
    {
        $user = $this->createUser($email, phone: $phone, phoneVerified: $phoneVerified);
        $this->signIn($user);

        return $user;
    }

    /** Crée et connecte un compte Google (sans mot de passe). */
    private function signedInGoogleClient(): User
    {
        $idp = static::getContainer()->get(FakeOidcProvider::class);
        $this->client->request('GET', '/auth/social/google');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);
        $idp->claims = FakeOidcProvider::googleClaims($q['nonce']);
        $this->client->request('GET', '/auth/social/google/callback?'.http_build_query(['state' => $q['state'], 'code' => 'abc']));
        $this->refreshCsrf();

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jean@example.com']);
        self::assertNotNull($user);
        self::assertFalse($user->hasPassword());

        return $user;
    }

    // ------------------------------------------------------------------ Accès

    public function testVisitorGets401AndCsrfIsRequired(): void
    {
        $this->api('POST', '/compte/api/mot-de-passe', ['current' => 'x', 'new' => 'y']);
        self::assertSame(401, $this->httpStatus());

        $this->signedInClient();
        $this->api('POST', '/compte/api/mot-de-passe', ['current' => self::PASSWORD, 'new' => self::NEW_PASSWORD], withCsrf: false);
        self::assertSame(403, $this->httpStatus());
    }

    // ------------------------------------------------------------------ Mot de passe

    public function testPasswordChangeNeedsTheCurrentPassword(): void
    {
        $user = $this->signedInClient();
        $hashBefore = $this->reload($user)->getPassword();

        $r = $this->api('POST', '/compte/api/mot-de-passe', ['current' => 'mauvais', 'new' => self::NEW_PASSWORD]);

        self::assertSame(403, $this->httpStatus());
        self::assertSame('step_up_failed', $r['error']);
        self::assertSame('Mot de passe incorrect.', $r['message']);
        self::assertSame($hashBefore, $this->reload($user)->getPassword(), 'Le mot de passe n\'a pas changé.');
    }

    public function testPasswordChangeKeepsThisSessionButClosesTheOthers(): void
    {
        $user = $this->createUser('c@example.com');
        // Session A (un autre appareil) : on garde ses cookies de côté.
        $this->signIn($user);
        $deviceA = $this->client->getCookieJar()->all();
        // Session B (celle qui change le mot de passe).
        $this->newBrowser();
        $this->signIn($user);

        $r = $this->api('POST', '/compte/api/mot-de-passe', ['current' => self::PASSWORD, 'new' => self::NEW_PASSWORD]);

        self::assertSame(200, $this->httpStatus());
        self::assertTrue($r['ok']);
        self::assertArrayNotHasKey('redirect', $r);
        self::assertEmailCount(1); // avis de sécurité « mot de passe modifié »

        // La session en cours reste ouverte…
        $this->client->request('GET', '/compte');
        self::assertResponseIsSuccessful();

        // … l'autre appareil est déconnecté.
        $this->client->getCookieJar()->clear();
        foreach ($deviceA as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects();

        // Ancien mot de passe refusé, nouveau accepté.
        $this->newBrowser();
        $this->api('POST', '/auth/login', ['email' => 'c@example.com', 'password' => self::PASSWORD]);
        self::assertSame(401, $this->httpStatus());
        $r = $this->api('POST', '/auth/login', ['email' => 'c@example.com', 'password' => self::NEW_PASSWORD]);
        self::assertSame('done', $r['step']);

        $log = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'auth.password.changed']);
        self::assertNotNull($log);
        self::assertSame('c@example.com', $log->getActorEmail());
        self::assertStringNotContainsString(self::NEW_PASSWORD, json_encode($log->getMetadata(), \JSON_THROW_ON_ERROR), 'Jamais de mot de passe dans le journal.');
    }

    public function testPasswordChangeRefusesSameAndWeakPasswords(): void
    {
        $this->signedInClient();

        $r = $this->api('POST', '/compte/api/mot-de-passe', ['current' => self::PASSWORD, 'new' => self::PASSWORD]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('same_password', $r['error']);

        $r = $this->api('POST', '/compte/api/mot-de-passe', ['current' => self::PASSWORD, 'new' => 'abc']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('weak_password', $r['error']);
        self::assertStringStartsWith('Mot de passe trop faible.', $r['message']);
    }

    public function testWrongPasswordGuessesAreLimited(): void
    {
        $this->signedInClient();

        for ($i = 0; $i < 5; ++$i) {
            $this->api('POST', '/compte/api/mot-de-passe', ['current' => 'mauvais-'.$i, 'new' => self::NEW_PASSWORD]);
            self::assertSame(403, $this->httpStatus());
        }
        // 6e essai : bloqué, même avec le bon mot de passe (un voleur de session ne peut pas deviner en boucle).
        $r = $this->api('POST', '/compte/api/mot-de-passe', ['current' => self::PASSWORD, 'new' => self::NEW_PASSWORD]);
        self::assertSame(429, $this->httpStatus());
        self::assertSame('step_up_locked', $r['error']);
    }

    public function testSocialAccountHasNoPasswordToChange(): void
    {
        $this->signedInGoogleClient();

        $r = $this->api('POST', '/compte/api/mot-de-passe', ['current' => '', 'new' => self::NEW_PASSWORD]);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('no_password', $r['error']);
    }

    // ------------------------------------------------------------------ Double authentification

    public function testTwoFactorByEmailCanBeEnabledAndIsThenRequiredAtLogin(): void
    {
        $user = $this->signedInClient();

        $r = $this->api('POST', '/compte/api/double-authentification', ['email' => true, 'sms' => false, 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['email' => true, 'sms' => false], $r['twoFactor']);
        self::assertTrue($this->reload($user)->isTwoFactorEmailEnabled());

        $this->newBrowser();
        $r = $this->api('POST', '/auth/login', ['email' => 'c@example.com', 'password' => self::PASSWORD]);
        self::assertSame('two_factor', $r['step']);
    }

    public function testTwoFactorChangeNeedsTheCorrectPassword(): void
    {
        $user = $this->signedInClient();

        $r = $this->api('POST', '/compte/api/double-authentification', ['email' => true, 'sms' => false, 'password' => 'mauvais']);

        self::assertSame(403, $this->httpStatus());
        self::assertSame('step_up_failed', $r['error']);
        self::assertFalse($this->reload($user)->isTwoFactorEmailEnabled());
    }

    public function testTwoFactorBySmsNeedsAVerifiedPhone(): void
    {
        $user = $this->signedInClient('c@example.com', '+261341234567', phoneVerified: false);

        $r = $this->api('POST', '/compte/api/double-authentification', ['email' => false, 'sms' => true, 'password' => self::PASSWORD]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('sms_needs_verified_phone', $r['error']);
        self::assertFalse($this->reload($user)->isTwoFactorSmsEnabled());
    }

    public function testTwoFactorBySmsWorksWithAVerifiedPhoneAndCanBeSwitchedOff(): void
    {
        $user = $this->signedInClient('c@example.com', '+261341234567', phoneVerified: true);

        $r = $this->api('POST', '/compte/api/double-authentification', ['email' => false, 'sms' => true, 'password' => self::PASSWORD]);
        self::assertSame(['email' => false, 'sms' => true], $r['twoFactor']);
        self::assertTrue($this->reload($user)->isTwoFactorSmsEnabled());

        $r = $this->api('POST', '/compte/api/double-authentification', ['email' => false, 'sms' => false, 'password' => self::PASSWORD]);
        self::assertSame(['email' => false, 'sms' => false], $r['twoFactor']);
        self::assertFalse($this->reload($user)->isTwoFactorEnabled());
    }

    public function testTwoFactorOnlyAcceptsRealBooleans(): void
    {
        $user = $this->signedInClient();

        // « "false" » (texte) ne doit surtout pas être lu comme vrai.
        $r = $this->api('POST', '/compte/api/double-authentification', ['email' => 'false', 'sms' => 'false', 'password' => self::PASSWORD]);
        self::assertSame(['email' => false, 'sms' => false], $r['twoFactor']);
        self::assertFalse($this->reload($user)->isTwoFactorEnabled());
    }

    public function testAdministratorsCannotChangeTheirMandatoryTwoFactor(): void
    {
        $admin = $this->createAdminUser();
        $this->signInAdmin($admin);

        $r = $this->api('POST', '/compte/api/double-authentification', ['email' => false, 'sms' => false, 'password' => self::PASSWORD]);

        self::assertSame(403, $this->httpStatus());
        self::assertSame('admin_two_factor_mandatory', $r['error']);
    }

    // ------------------------------------------------------------------ Téléphone

    public function testUnverifiedPhoneCanBeAddedAndVerifiedWithoutPassword(): void
    {
        $user = $this->signedInClient();

        $r = $this->api('POST', '/compte/api/telephone', ['phone' => '034 12 345 67']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('sent', $r['state']);
        self::assertSame('+261341234567', $this->reload($user)->getPhone());

        $r = $this->api('POST', '/compte/api/telephone/verifier', ['code' => $this->otp()->lastCode(OtpPurpose::PhoneVerification, OtpChannel::Sms)]);
        self::assertSame('verified', $r['state']);
        self::assertTrue($this->reload($user)->isPhoneVerified());
    }

    public function testInvalidPhoneIsRejected(): void
    {
        $user = $this->signedInClient();

        $r = $this->api('POST', '/compte/api/telephone', ['phone' => '12345']);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_phone', $r['error']);
        self::assertNull($this->reload($user)->getPhone());
    }

    public function testVerifiedPhoneCannotBeChangedOrRemovedThroughTheLightEndpoint(): void
    {
        $user = $this->signedInClient('c@example.com', '+261341234567', phoneVerified: true);

        // Remplacer ou retirer un numéro DÉJÀ vérifié passe par la double confirmation (voir ContactChangeTest).
        $r = $this->api('POST', '/compte/api/telephone', ['phone' => '032 11 222 33', 'password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('contact_change_required', $r['error']);

        $r = $this->api('POST', '/compte/api/telephone', ['phone' => '', 'password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('contact_change_required', $r['error']);

        $user = $this->reload($user);
        self::assertSame('+261341234567', $user->getPhone());
        self::assertTrue($user->isPhoneVerified());
    }

    public function testUnverifiedPhoneCanBeCorrectedOrRemovedWithoutConfirmation(): void
    {
        $user = $this->signedInClient('c@example.com', '+261341234567');

        $r = $this->api('POST', '/compte/api/telephone', ['phone' => '032 11 222 33']);
        self::assertSame('sent', $r['state']);
        self::assertSame('+261321122233', $this->reload($user)->getPhone());

        $r = $this->api('POST', '/compte/api/telephone', ['phone' => '']);
        self::assertSame('verified', $r['state']);
        self::assertNull($this->reload($user)->getPhone());
    }

    public function testSameVerifiedPhoneIsANoOpWithoutConfirmation(): void
    {
        $user = $this->signedInClient('c@example.com', '+261341234567', phoneVerified: true);

        $r = $this->api('POST', '/compte/api/telephone', ['phone' => '+261 34 12 345 67']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('verified', $r['state']);
        self::assertTrue($this->reload($user)->isPhoneVerified());
    }

    // ------------------------------------------------------------------ Comptes Google / Apple : code par e-mail

    public function testSocialAccountConfirmsSensitiveChangesWithAnEmailedCode(): void
    {
        $user = $this->signedInGoogleClient();

        // Sans code : demandé.
        $r = $this->api('POST', '/compte/api/double-authentification', ['email' => true, 'sms' => false]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('code_required', $r['error']);

        // Envoi du code à l'adresse du compte.
        $r = $this->api('POST', '/compte/api/confirmation/envoyer', ['purpose' => 'security']);
        self::assertSame(200, $this->httpStatus());
        self::assertGreaterThan(0, $r['resendIn']);
        $code = $this->otp()->lastCode(OtpPurpose::AccountSecurity, OtpChannel::Email);

        // Faux code : refusé.
        $bad = '000000' === $code ? '111111' : '000000';
        $r = $this->api('POST', '/compte/api/double-authentification', ['email' => true, 'sms' => false, 'code' => $bad]);
        self::assertSame('invalid_code', $r['error']);
        self::assertFalse($this->reload($user)->isTwoFactorEmailEnabled());

        // Bon code : accepté.
        $r = $this->api('POST', '/compte/api/double-authentification', ['email' => true, 'sms' => false, 'code' => $code]);
        self::assertSame(200, $this->httpStatus());
        self::assertTrue($this->reload($user)->isTwoFactorEmailEnabled());
    }

    public function testConfirmationCodeIsBoundToItsPurpose(): void
    {
        $this->signedInGoogleClient();
        $this->api('POST', '/compte/api/confirmation/envoyer', ['purpose' => 'security']);
        $securityCode = $this->otp()->lastCode(OtpPurpose::AccountSecurity);

        // Un code « réglage de sécurité » ne peut pas servir à supprimer le compte.
        $r = $this->api('POST', '/compte/api/suppression', ['code' => $securityCode]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('code_expired', $r['error']);
    }

    public function testAccountWithPasswordDoesNotNeedAnEmailedCode(): void
    {
        $this->signedInClient();

        $r = $this->api('POST', '/compte/api/confirmation/envoyer', ['purpose' => 'security']);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('code_not_needed', $r['error']);
    }
}
