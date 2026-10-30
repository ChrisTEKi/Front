<?php

namespace App\Tests\Account;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Clock\Clock;

/**
 * Changement d'adresse e-mail ou de téléphone avec DOUBLE confirmation :
 * code à l'ancienne adresse, puis code à la nouvelle destination ; rien n'est modifié avant le second code.
 */
final class ContactChangeTest extends AppTestCase
{
    private const START = '/compte/api/contact/demarrer';
    private const CONFIRM = '/compte/api/contact/confirmer';
    private const RESEND = '/compte/api/contact/renvoyer';
    private const CANCEL = '/compte/api/contact/annuler';

    private function signedInClient(string $email = 'old@example.com', ?string $phone = null, bool $phoneVerified = false): User
    {
        $user = $this->createUser($email, phone: $phone, phoneVerified: $phoneVerified);
        $this->signIn($user);

        return $user;
    }

    /** Étape 1 réussie : le code part vers l'ANCIENNE adresse. Retourne ce code. */
    private function startAndConfirmOld(string $kind, string $value): string
    {
        $r = $this->api('POST', self::START, ['kind' => $kind, 'value' => $value]);
        self::assertSame(200, $this->httpStatus(), json_encode($r));
        self::assertSame('sent', $r['state']);
        self::assertSame('old', $r['step']);

        return $this->otp()->lastCode(OtpPurpose::ContactChangeOld, OtpChannel::Email);
    }

    // ------------------------------------------------------------------ E-mail : parcours complet

    public function testEmailChangeNeedsBothCodesAndWritesNothingBeforeTheSecond(): void
    {
        $user = $this->signedInClient();

        $oldCode = $this->startAndConfirmOld('email', 'New.Address@Example.com');
        // Le code est parti vers l'ANCIENNE adresse.
        $sent = $this->otp()->sent[array_key_last($this->otp()->sent)];
        self::assertSame('old@example.com', $sent['target']);
        self::assertSame(OtpChannel::Email, $sent['channel']);

        $r = $this->api('POST', self::CONFIRM, ['code' => $oldCode]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('sent', $r['state']);
        self::assertSame('new', $r['step']);
        self::assertSame('n•••@example.com', $r['maskedTarget']);
        // Le second code est parti vers la NOUVELLE adresse (en minuscules) ; le compte n'a pas encore changé.
        $sent = $this->otp()->sent[array_key_last($this->otp()->sent)];
        self::assertSame(OtpPurpose::ContactChangeNew, $sent['purpose']);
        self::assertSame('new.address@example.com', $sent['target']);
        self::assertSame('old@example.com', $this->reload($user)->getEmail());

        $r = $this->api('POST', self::CONFIRM, ['code' => $this->otp()->lastCode(OtpPurpose::ContactChangeNew)]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('verified', $r['state']);
        self::assertSame('email', $r['kind']);

        $user = $this->reload($user);
        self::assertSame('new.address@example.com', $user->getEmail());
        self::assertNotNull($user->getEmailVerifiedAt());

        // Avis envoyé à l'ANCIENNE adresse, avec la nouvelle masquée.
        self::assertEmailCount(1);
        $mail = self::getMailerMessage(0);
        self::assertEmailHeaderSame($mail, 'To', 'Test Client <old@example.com>');
        self::assertEmailTextBodyContains($mail, 'n•••@example.com');
        self::assertStringNotContainsString('new.address', (string) $mail->getTextBody());

        // Journal : adresses masquées, jamais en clair.
        $log = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'account.email.changed']);
        self::assertNotNull($log);
        self::assertStringNotContainsString('new.address', json_encode($log->getMetadata(), \JSON_THROW_ON_ERROR));
    }

    public function testEmailChangeKeepsThisSessionAndClosesTheOthers(): void
    {
        $user = $this->createUser('old@example.com');
        $this->signIn($user);
        $deviceA = $this->client->getCookieJar()->all();
        $this->newBrowser();
        $this->signIn($user);

        $this->startAndConfirmOld('email', 'new@example.com');
        $this->api('POST', self::CONFIRM, ['code' => $this->otp()->lastCode(OtpPurpose::ContactChangeOld)]);
        $this->api('POST', self::CONFIRM, ['code' => $this->otp()->lastCode(OtpPurpose::ContactChangeNew)]);
        $this->refreshCsrf();

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

        // On se connecte désormais avec la nouvelle adresse seulement.
        $this->newBrowser();
        $this->api('POST', '/auth/login', ['email' => 'old@example.com', 'password' => self::PASSWORD]);
        self::assertSame(401, $this->httpStatus());
        $r = $this->api('POST', '/auth/login', ['email' => 'new@example.com', 'password' => self::PASSWORD]);
        self::assertSame('done', $r['step']);
    }

    public function testWrongOldCodeBlocksEverythingAndNoSecondCodeIsSent(): void
    {
        $user = $this->signedInClient();
        $this->startAndConfirmOld('email', 'new@example.com');

        $r = $this->api('POST', self::CONFIRM, ['code' => '000000']);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_code', $r['error']);
        self::assertSame(4, $r['attemptsLeft']);
        self::assertSame(0, $this->otp()->count(OtpPurpose::ContactChangeNew), 'Aucun code vers la nouvelle adresse sans preuve sur l\'ancienne.');
        self::assertSame('old@example.com', $this->reload($user)->getEmail());
    }

    public function testNewEmailCodeCannotBeReplacedByTheOldOne(): void
    {
        $user = $this->signedInClient();
        $oldCode = $this->startAndConfirmOld('email', 'new@example.com');
        $this->api('POST', self::CONFIRM, ['code' => $oldCode]);

        // Le code de l'ancienne adresse a été consommé : il ne sert pas pour la seconde étape.
        $r = $this->api('POST', self::CONFIRM, ['code' => $oldCode]);

        self::assertSame(422, $this->httpStatus());
        self::assertContains($r['error'], ['invalid_code', 'code_expired']);
        self::assertSame('old@example.com', $this->reload($user)->getEmail());
    }

    public function testFiveWrongCodesLockTheStep(): void
    {
        $this->signedInClient();
        $this->startAndConfirmOld('email', 'new@example.com');

        for ($i = 0; $i < 5; ++$i) {
            $r = $this->api('POST', self::CONFIRM, ['code' => '000000']);
        }

        self::assertSame('code_locked', $r['error']);
    }

    public function testRequestExpiresAfterThirtyMinutes(): void
    {
        $user = $this->signedInClient();
        $oldCode = $this->startAndConfirmOld('email', 'new@example.com');

        static::mockTime(Clock::get()->now()->modify('+31 minutes'));
        $r = $this->api('POST', self::CONFIRM, ['code' => $oldCode]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('contact_change_expired', $r['error']);
        self::assertSame('old@example.com', $this->reload($user)->getEmail());
    }

    public function testConfirmWithoutARequestIsRefused(): void
    {
        $this->signedInClient();

        $r = $this->api('POST', self::CONFIRM, ['code' => '123456']);

        self::assertSame('contact_change_expired', $r['error']);
    }

    public function testCancelDropsTheRequest(): void
    {
        $this->signedInClient();
        $oldCode = $this->startAndConfirmOld('email', 'new@example.com');

        $r = $this->api('POST', self::CANCEL);
        self::assertTrue($r['ok']);

        $r = $this->api('POST', self::CONFIRM, ['code' => $oldCode]);
        self::assertSame('contact_change_expired', $r['error']);
    }

    public function testInvalidSameAndEmptyEmailsAreRefused(): void
    {
        $this->signedInClient();

        self::assertSame('invalid_email', $this->api('POST', self::START, ['kind' => 'email', 'value' => 'pas-un-email'])['error']);
        self::assertSame('same_email', $this->api('POST', self::START, ['kind' => 'email', 'value' => 'OLD@example.com'])['error']);
        self::assertSame('email_required', $this->api('POST', self::START, ['kind' => 'email', 'value' => ''])['error']);
        self::assertSame('invalid_email', $this->api('POST', self::START, ['kind' => 'email', 'value' => str_repeat('a', 200).'@example.com'])['error']);
        self::assertSame('invalid_request', $this->api('POST', self::START, ['kind' => 'password', 'value' => 'x'])['error']);
        self::assertSame(0, $this->otp()->count(), 'Aucun code tant que la demande est invalide.');
    }

    public function testCorrectingTheTargetDoesNotResendTheOldAddressCode(): void
    {
        $user = $this->signedInClient();
        $oldCode = $this->startAndConfirmOld('email', 'typo@example.com');

        // Faute de frappe corrigée : même code, aucun nouvel e-mail, pas de délai d'attente.
        $r = $this->api('POST', self::START, ['kind' => 'email', 'value' => 'right@example.com']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(1, $this->otp()->count(OtpPurpose::ContactChangeOld));

        $this->api('POST', self::CONFIRM, ['code' => $oldCode]);
        self::assertSame('right@example.com', $this->otp()->sent[array_key_last($this->otp()->sent)]['target']);
        $this->api('POST', self::CONFIRM, ['code' => $this->otp()->lastCode(OtpPurpose::ContactChangeNew)]);
        self::assertSame('right@example.com', $this->reload($user)->getEmail());
    }

    public function testResendFollowsTheCurrentStepAndHonoursTheDelay(): void
    {
        $this->signedInClient();
        $this->startAndConfirmOld('email', 'new@example.com');

        $r = $this->api('POST', self::RESEND);
        self::assertSame(429, $this->httpStatus());
        self::assertSame('too_soon', $r['error']);

        static::mockTime(Clock::get()->now()->modify('+40 seconds'));
        $r = $this->api('POST', self::RESEND);
        self::assertSame('sent', $r['state']);
        self::assertSame('old', $r['step']);
        self::assertSame(2, $this->otp()->count(OtpPurpose::ContactChangeOld));
    }

    // ------------------------------------------------------------------ Anti-énumération : l'adresse appartient déjà à quelqu'un

    public function testTakenNewEmailLooksIdenticalButSendsNoCodeAndWarnsTheOwner(): void
    {
        $user = $this->signedInClient();
        $this->createUser('taken@example.com');

        $oldCode = $this->startAndConfirmOld('email', 'taken@example.com');
        $r = $this->api('POST', self::CONFIRM, ['code' => $oldCode]);

        // Même écran qu'une vraie étape 2…
        self::assertSame(200, $this->httpStatus());
        self::assertSame('sent', $r['state']);
        self::assertSame('new', $r['step']);
        self::assertSame('t•••@example.com', $r['maskedTarget']);
        // … mais aucun code n'a été émis ; le titulaire reçoit un avis.
        self::assertSame(0, $this->otp()->count(OtpPurpose::ContactChangeNew));
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'To', 'Test Client <taken@example.com>');

        // Toute saisie échoue comme un code faux, puis le compteur d'essais se bloque comme pour de vrai.
        $r = $this->api('POST', self::CONFIRM, ['code' => '123456']);
        self::assertSame('invalid_code', $r['error']);
        self::assertSame(4, $r['attemptsLeft']);
        for ($i = 0; $i < 3; ++$i) {
            $this->api('POST', self::CONFIRM, ['code' => '123456']);
        }
        $r = $this->api('POST', self::CONFIRM, ['code' => '123456']);
        self::assertSame('code_locked', $r['error']);

        self::assertSame('old@example.com', $this->reload($user)->getEmail());
        self::assertNotNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'taken@example.com']));
    }

    public function testDecoyResendImitatesTheRealDelay(): void
    {
        $this->signedInClient();
        $this->createUser('taken@example.com');
        $this->api('POST', self::START, ['kind' => 'email', 'value' => 'taken@example.com']);
        $this->api('POST', self::CONFIRM, ['code' => $this->otp()->lastCode(OtpPurpose::ContactChangeOld)]);

        $r = $this->api('POST', self::RESEND);
        self::assertSame(429, $this->httpStatus());
        self::assertSame('too_soon', $r['error']);

        static::mockTime(Clock::get()->now()->modify('+40 seconds'));
        $r = $this->api('POST', self::RESEND);
        self::assertSame('sent', $r['state']);
        self::assertSame('new', $r['step']);
        self::assertSame(0, $this->otp()->count(OtpPurpose::ContactChangeNew));
    }

    public function testOwnerGetsAtMostThreeNoticesPerDay(): void
    {
        $this->createUser('taken@example.com');

        $notices = [];
        for ($i = 1; $i <= 4; ++$i) {
            $this->newBrowser();
            $user = $this->createUser('user'.$i.'@example.com');
            $this->signIn($user);
            $this->api('POST', self::START, ['kind' => 'email', 'value' => 'taken@example.com']);
            $r = $this->api('POST', self::CONFIRM, ['code' => $this->otp()->lastCode(OtpPurpose::ContactChangeOld)]);
            self::assertSame('sent', $r['state'], 'La réponse est la même, avis envoyé ou non.');
            // Les e-mails comptés sont ceux de la DERNIÈRE requête : on relève donc le total après chacune.
            $notices[] = \count(self::getMailerMessages());
        }

        self::assertSame([1, 1, 1, 0], $notices);
    }

    public function testRealNewEmailIsRateLimitedPerDestination(): void
    {
        // Au plus 5 codes par jour vers une même adresse, quels que soient les comptes demandeurs.
        for ($i = 1; $i <= 6; ++$i) {
            $this->newBrowser();
            $this->signIn($this->createUser('user'.$i.'@example.com'));
            $this->api('POST', self::START, ['kind' => 'email', 'value' => 'victim@example.com']);
            $r = $this->api('POST', self::CONFIRM, ['code' => $this->otp()->lastCode(OtpPurpose::ContactChangeOld)]);
            if ($i <= 5) {
                self::assertSame('sent', $r['state'], 'envoi n° '.$i);
            }
        }

        self::assertSame('too_soon', $r['error']);
        self::assertSame(5, $this->otp()->count(OtpPurpose::ContactChangeNew));
    }

    // ------------------------------------------------------------------ Téléphone

    public function testPhoneChangeNeedsOldEmailCodeThenSmsToTheNewNumber(): void
    {
        $user = $this->signedInClient('old@example.com', '+261341234567', phoneVerified: true);
        $this->api('POST', '/compte/api/double-authentification', ['email' => false, 'sms' => true, 'password' => self::PASSWORD]);
        self::assertTrue($this->reload($user)->isTwoFactorSmsEnabled());

        $oldCode = $this->startAndConfirmOld('phone', '032 11 222 33');
        // Aucun SMS avant la preuve de l'ancienne adresse.
        self::assertSame(0, $this->otp()->count(OtpPurpose::ContactChangeNew));

        $r = $this->api('POST', self::CONFIRM, ['code' => $oldCode]);
        self::assertSame('sent', $r['state']);
        self::assertSame('new', $r['step']);
        $sent = $this->otp()->sent[array_key_last($this->otp()->sent)];
        self::assertSame(OtpChannel::Sms, $sent['channel']);
        self::assertSame('+261321122233', $sent['target']);
        // Rien n'a encore changé.
        self::assertSame('+261341234567', $this->reload($user)->getPhone());

        $r = $this->api('POST', self::CONFIRM, ['code' => $sent['code']]);
        self::assertSame('verified', $r['state']);
        self::assertSame('phone', $r['kind']);

        $user = $this->reload($user);
        self::assertSame('+261321122233', $user->getPhone());
        self::assertTrue($user->isPhoneVerified(), 'Le nouveau numéro a été prouvé par SMS.');
        self::assertTrue($user->isTwoFactorSmsEnabled(), 'La 2FA par SMS reste active : le nouveau numéro est vérifié.');
        self::assertEmailCount(1); // avis « téléphone modifié »

        $log = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'account.phone.changed']);
        self::assertNotNull($log);
        self::assertStringNotContainsString('2112233', json_encode($log->getMetadata(), \JSON_THROW_ON_ERROR));
    }

    public function testPhoneChangeIsRefusedWhenTheNumberIsVerifiedElsewhere(): void
    {
        $user = $this->signedInClient('old@example.com', '+261341234567', phoneVerified: true);
        $this->createUser('other@example.com', phone: '+261321122233', phoneVerified: true);

        $oldCode = $this->startAndConfirmOld('phone', '032 11 222 33');
        $this->api('POST', self::CONFIRM, ['code' => $oldCode]);
        $r = $this->api('POST', self::CONFIRM, ['code' => $this->otp()->lastCode(OtpPurpose::ContactChangeNew)]);

        self::assertSame('phone_taken', $r['error']);
        self::assertSame(409, $this->httpStatus());
        $user = $this->reload($user);
        self::assertSame('+261341234567', $user->getPhone());
        self::assertTrue($user->isPhoneVerified());
    }

    public function testPhoneRemovalOnlyNeedsTheOldEmailCode(): void
    {
        $user = $this->signedInClient('old@example.com', '+261341234567', phoneVerified: true);

        $oldCode = $this->startAndConfirmOld('phone', '');
        self::assertSame('+261341234567', $this->reload($user)->getPhone(), 'Rien ne change avant le code.');

        $r = $this->api('POST', self::CONFIRM, ['code' => $oldCode]);

        self::assertSame('verified', $r['state']);
        $user = $this->reload($user);
        self::assertNull($user->getPhone());
        self::assertFalse($user->isPhoneVerified());
        self::assertSame(0, $this->otp()->count(OtpPurpose::ContactChangeNew));
    }

    public function testSupplierCannotRemoveTheirPhoneButCanReplaceIt(): void
    {
        $supplier = $this->createUser('boutique@example.com', AccountType::Supplier, true, '+261331112233', true);
        $this->signIn($supplier);

        $r = $this->api('POST', self::START, ['kind' => 'phone', 'value' => '']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('supplier_phone_required', $r['error']);

        $oldCode = $this->startAndConfirmOld('phone', '034 12 345 67');
        $this->api('POST', self::CONFIRM, ['code' => $oldCode]);
        $r = $this->api('POST', self::CONFIRM, ['code' => $this->otp()->lastCode(OtpPurpose::ContactChangeNew)]);
        self::assertSame('verified', $r['state']);
        self::assertSame('+261341234567', $this->reload($supplier)->getPhone());
    }

    public function testSameVerifiedPhoneAndInvalidPhoneAreRefused(): void
    {
        $this->signedInClient('old@example.com', '+261341234567', phoneVerified: true);

        self::assertSame('same_phone', $this->api('POST', self::START, ['kind' => 'phone', 'value' => '034 12 345 67'])['error']);
        self::assertSame('invalid_phone', $this->api('POST', self::START, ['kind' => 'phone', 'value' => '12345'])['error']);
    }

    // ------------------------------------------------------------------ Autres types de compte

    public function testAdminCanChangeTheirEmailToo(): void
    {
        $admin = $this->createAdminUser('admin@example.com');
        $this->signInAdmin($admin);

        $oldCode = $this->startAndConfirmOld('email', 'admin.new@example.com');
        $this->api('POST', self::CONFIRM, ['code' => $oldCode]);
        $r = $this->api('POST', self::CONFIRM, ['code' => $this->otp()->lastCode(OtpPurpose::ContactChangeNew)]);

        self::assertSame('verified', $r['state']);
        self::assertSame('admin.new@example.com', $this->reload($admin)->getEmail());
    }

    public function testVisitorCannotUseTheContactApi(): void
    {
        $this->api('POST', self::START, ['kind' => 'email', 'value' => 'a@example.com']);

        self::assertSame(401, $this->httpStatus());
    }
}
