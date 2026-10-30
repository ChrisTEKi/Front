<?php

namespace App\Tests\Auth;

use App\Auth\Exception\OtpSendRefusedException;
use App\Auth\OtpResult;
use App\Auth\OtpService;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Tests\Support\AppTestCase;

/**
 * Règles de sécurité des codes à usage unique : stockage haché, expiration, usage unique, liaison à la
 * finalité et à la destination, délai entre envois, quotas.
 */
final class OtpServiceTest extends AppTestCase
{
    private OtpService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-19 10:00:00 UTC'); // horloge simulée : on peut « avancer le temps »
        $this->service = static::getContainer()->get(OtpService::class);
        $this->user = $this->reload($this->createUser('jean@example.com'));
    }

    public function testCodeIsStoredHashedNeverInClear(): void
    {
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        $code = $this->otp()->lastCode(OtpPurpose::EmailVerification);

        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        $stored = $this->em()->getConnection()->fetchOne('SELECT code_hash FROM one_time_codes');
        self::assertNotSame($code, $stored);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored); // HMAC-SHA256
        self::assertStringNotContainsString($code, (string) json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM one_time_codes')));
    }

    public function testValidCodeWorksOnceOnly(): void
    {
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        $code = $this->otp()->lastCode(OtpPurpose::EmailVerification);

        self::assertSame(OtpResult::Valid, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $code)->result);
        // Rejouer le même code : refusé.
        self::assertSame(OtpResult::Expired, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $code)->result);
    }

    public function testSpacesAndDashesInTypedCodeAreTolerated(): void
    {
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        $code = $this->otp()->lastCode(OtpPurpose::EmailVerification);

        $typed = substr($code, 0, 3).' - '.substr($code, 3);
        self::assertSame(OtpResult::Valid, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $typed)->result);
    }

    public function testCodeExpiresAfterTenMinutes(): void
    {
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        $code = $this->otp()->lastCode(OtpPurpose::EmailVerification);

        static::mockTime('2026-10-19 10:10:01 UTC');

        self::assertSame(OtpResult::Expired, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $code)->result);
    }

    public function testCodeStillValidJustBeforeExpiry(): void
    {
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        $code = $this->otp()->lastCode(OtpPurpose::EmailVerification);

        static::mockTime('2026-10-19 10:09:59 UTC');

        self::assertSame(OtpResult::Valid, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $code)->result);
    }

    public function testCodeIsBoundToPurpose(): void
    {
        $this->service->issue($this->user, OtpPurpose::TwoFactor, OtpChannel::Email, 'jean@example.com');
        $code = $this->otp()->lastCode(OtpPurpose::TwoFactor);

        // Un code de connexion 2FA ne réinitialise pas un mot de passe.
        self::assertSame(OtpResult::Expired, $this->service->verify($this->user, OtpPurpose::PasswordReset, 'jean@example.com', $code)->result);
    }

    public function testCodeIsBoundToDestination(): void
    {
        $this->service->issue($this->user, OtpPurpose::PhoneVerification, OtpChannel::Sms, '+261341234567');
        $code = $this->otp()->lastCode(OtpPurpose::PhoneVerification);

        // Le code envoyé à l'ancien numéro ne valide pas un autre numéro.
        self::assertSame(OtpResult::Expired, $this->service->verify($this->user, OtpPurpose::PhoneVerification, '+261331234567', $code)->result);
    }

    public function testCodeIsBoundToUser(): void
    {
        $this->createUser('autre@example.com'); // (createUser vide l'EntityManager : on recharge les deux comptes ensuite)
        $repository = $this->em()->getRepository(User::class);
        $user = $repository->findOneBy(['email' => 'jean@example.com']);
        $other = $repository->findOneBy(['email' => 'autre@example.com']);

        $this->service->issue($user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        $code = $this->otp()->lastCode(OtpPurpose::EmailVerification);

        self::assertSame(OtpResult::Expired, $this->service->verify($other, OtpPurpose::EmailVerification, 'jean@example.com', $code)->result);
    }

    public function testFiveWrongAttemptsLockEvenTheGoodCode(): void
    {
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        $code = $this->otp()->lastCode(OtpPurpose::EmailVerification);
        $bad = '000000' === $code ? '111111' : '000000';

        for ($left = 4; $left >= 1; --$left) {
            $check = $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $bad);
            self::assertSame(OtpResult::Invalid, $check->result);
            self::assertSame($left, $check->attemptsLeft);
        }
        self::assertSame(OtpResult::Locked, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $bad)->result);
        self::assertSame(OtpResult::Locked, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $code)->result);
    }

    public function testFailuresAreCountedAcrossCodesNotOnlyPerCode(): void
    {
        // Un attaquant a déjà épuisé le budget d'échecs de ce compte avec plusieurs codes successifs (15 par 6 h).
        // (On consomme le compteur directement : l'envoi de codes est lui-même limité à 3 par 10 min en temps réel.)
        $limiter = static::getContainer()->get('limiter.otp_fail_user')->create($this->user->getId()->toRfc4122().'|'.OtpPurpose::PasswordReset->value);
        $limiter->consume(15);

        // Un nouveau code, même le BON : refusé tant que la fenêtre de 6 h n'est pas écoulée.
        $this->service->issue($this->user, OtpPurpose::PasswordReset, OtpChannel::Email, 'jean@example.com');
        $code = $this->otp()->lastCode(OtpPurpose::PasswordReset);
        self::assertSame(OtpResult::Locked, $this->service->verify($this->user, OtpPurpose::PasswordReset, 'jean@example.com', $code)->result);

        // Seulement pour CETTE finalité : la vérification de l'e-mail du même compte n'est pas touchée.
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        self::assertSame(OtpResult::Valid, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $this->otp()->lastCode(OtpPurpose::EmailVerification))->result);
    }

    public function testEachWrongTryCountsTowardTheCumulativeBudget(): void
    {
        $this->service->issue($this->user, OtpPurpose::PasswordReset, OtpChannel::Email, 'jean@example.com');
        for ($try = 0; $try < 5; ++$try) {
            $this->service->verify($this->user, OtpPurpose::PasswordReset, 'jean@example.com', '000000');
        }

        $limiter = static::getContainer()->get('limiter.otp_fail_user')->create($this->user->getId()->toRfc4122().'|'.OtpPurpose::PasswordReset->value);
        self::assertSame(10, $limiter->consume(0)->getRemainingTokens(), '15 − 5 échecs enregistrés.');
    }

    public function testSuccessForgetsEarlierTypos(): void
    {
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        for ($try = 0; $try < 4; ++$try) {
            $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', '000000');
        }
        self::assertSame(OtpResult::Valid, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $this->otp()->lastCode(OtpPurpose::EmailVerification))->result);

        // Un échec de plus ensuite repart de zéro : on peut refaire 14 fautes de frappe sur un autre code sans être bloqué.
        static::mockTime('2026-10-19 10:01:00 UTC');
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        self::assertSame(OtpResult::Invalid, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', '000000')->result);
    }

    public function testNewCodeInvalidatesThePreviousOne(): void
    {
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        $first = $this->otp()->lastCode(OtpPurpose::EmailVerification);

        static::mockTime('2026-10-19 10:01:00 UTC');
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        $second = $this->otp()->lastCode(OtpPurpose::EmailVerification);

        if ($first !== $second) { // (1 chance sur 10^6 que les deux codes soient identiques)
            self::assertNotSame(OtpResult::Valid, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $first)->result);
        }
        self::assertSame(OtpResult::Valid, $this->service->verify($this->user, OtpPurpose::EmailVerification, 'jean@example.com', $second)->result);
    }

    public function testResendCooldown(): void
    {
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');

        static::mockTime('2026-10-19 10:00:10 UTC');
        try {
            $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
            self::fail('Le délai entre envois aurait dû refuser.');
        } catch (OtpSendRefusedException $e) {
            self::assertSame('cooldown', $e->reason);
            self::assertSame(20, $e->retryAfterSeconds);
        }

        static::mockTime('2026-10-19 10:00:31 UTC');
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        self::assertSame(2, $this->otp()->count());
    }

    public function testSendQuotaPerUser(): void
    {
        // 3 envois par 10 minutes (en respectant le délai de 30 s entre chaque).
        foreach (['10:00:00', '10:00:40', '10:01:20'] as $time) {
            static::mockTime("2026-10-19 $time UTC");
            $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
        }

        static::mockTime('2026-10-19 10:02:00 UTC');
        $this->expectException(OtpSendRefusedException::class);
        $this->service->issue($this->user, OtpPurpose::EmailVerification, OtpChannel::Email, 'jean@example.com');
    }

    public function testSmsQuotaPerPhoneNumber(): void
    {
        // 8 SMS / jour vers un même numéro, même si ce sont des comptes ou des finalités différents.
        $purposes = [OtpPurpose::PhoneVerification, OtpPurpose::TwoFactor, OtpPurpose::PaymentPhone];
        $sent = 0;
        $minute = 0;
        try {
            for ($i = 0; $i < 12; ++$i) {
                $purpose = $purposes[$i % 3];
                // 3 envois max par finalité / 10 min : on espace les envois pour ne tester QUE le quota par numéro.
                static::mockTime(sprintf('2026-10-19 %02d:%02d:00 UTC', 10 + intdiv($i, 3), ($i % 3) * 5));
                $this->service->issue($this->user, $purpose, OtpChannel::Sms, '+261341234567');
                ++$sent;
            }
        } catch (OtpSendRefusedException $e) {
            self::assertSame('quota', $e->reason);
        }

        self::assertSame(8, $sent);
    }

    public function testSmsQuotaPerAccountStopsSmsPumpingTowardManyDifferentNumbers(): void
    {
        // 10 SMS / jour / compte, même vers 10 numéros DIFFÉRENTS (le plafond par numéro, lui, ne verrait rien).
        // Les limiteurs de débit comptent en temps réel : on alterne 4 finalités pour ne jamais dépasser « 3 envois / 10 min » par finalité,
        // et on espace les envois (heure simulée) pour respecter le délai de 30 s entre deux envois d'une même finalité.
        $purposes = [OtpPurpose::PhoneVerification, OtpPurpose::TwoFactor, OtpPurpose::PaymentPhone, OtpPurpose::PasswordReset];
        $sent = 0;
        try {
            for ($i = 0; $i < 12; ++$i) {
                static::mockTime(sprintf('2026-10-19 10:%02d:00 UTC', $i * 4));
                $this->service->issue($this->reload($this->user), $purposes[$i % 4], OtpChannel::Sms, sprintf('+26134%07d', 1000000 + $i));
                ++$sent;
            }
            self::fail('Le plafond par compte aurait dû refuser le 11e SMS.');
        } catch (OtpSendRefusedException $e) {
            self::assertSame('quota', $e->reason);
        }

        self::assertSame(10, $sent);
    }

    public function testSmsQuotaPerIpIsSharedByAllAccountsOfThatOrigin(): void
    {
        // Les comptes jetables créés depuis une même adresse IP partagent un plafond de 15 SMS / heure.
        $requests = static::getContainer()->get(\Symfony\Component\HttpFoundation\RequestStack::class);
        $requests->push(\Symfony\Component\HttpFoundation\Request::create('/', server: ['REMOTE_ADDR' => '198.51.100.20']));

        $accounts = [];
        for ($a = 0; $a < 3; ++$a) {
            $accounts[] = $this->createUser('autre'.$a.'@example.com');
        }
        $ids = array_map(static fn (User $u): string => $u->getId()->toRfc4122(), $accounts);

        $sent = 0;
        try {
            for ($i = 0; $i < 20; ++$i) {
                // 3 comptes à tour de rôle, 3 finalités en rotation : jamais plus de 3 envois / 10 min pour une même finalité d'un compte.
                $user = $this->em()->getRepository(User::class)->find($ids[$i % 3]);
                $purpose = [OtpPurpose::PhoneVerification, OtpPurpose::TwoFactor, OtpPurpose::PaymentPhone][intdiv($i, 3) % 3];
                static::mockTime(sprintf('2026-10-19 10:%02d:00 UTC', $i * 3));
                $this->service->issue($user, $purpose, OtpChannel::Sms, sprintf('+26134%07d', 2000000 + $i));
                ++$sent;
                $this->em()->clear();
            }
            self::fail('Le plafond par adresse IP aurait dû refuser le 16e SMS.');
        } catch (OtpSendRefusedException $e) {
            self::assertSame('quota', $e->reason);
        }

        self::assertSame(15, $sent);
    }

    public function testInvitationTokenIsLongAndValidForTwoDays(): void
    {
        $admin = $this->reload($this->createUser('admin@example.com', \App\Enum\AccountType::Admin));
        $this->service->issue($admin, OtpPurpose::AdminInvitation, OtpChannel::Email, 'admin@example.com');
        $token = $this->otp()->lastCode(OtpPurpose::AdminInvitation);

        self::assertGreaterThanOrEqual(43, \strlen($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token); // utilisable dans une URL

        static::mockTime('2026-10-21 09:59:00 UTC'); // 47 h 59 plus tard
        self::assertSame(OtpResult::Valid, $this->service->verify($admin, OtpPurpose::AdminInvitation, 'admin@example.com', $token)->result);
    }

    public function testInvitationExpiresAfterTwoDays(): void
    {
        $admin = $this->reload($this->createUser('admin@example.com', \App\Enum\AccountType::Admin));
        $this->service->issue($admin, OtpPurpose::AdminInvitation, OtpChannel::Email, 'admin@example.com');
        $token = $this->otp()->lastCode(OtpPurpose::AdminInvitation);

        static::mockTime('2026-10-21 10:00:01 UTC');
        self::assertSame(OtpResult::Expired, $this->service->verify($admin, OtpPurpose::AdminInvitation, 'admin@example.com', $token)->result);
    }
}
