<?php

namespace App\Tests\Payment;

use App\Entity\MobileMoneyAccount;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\OtpPurpose;
use App\Tests\Support\AppTestCase;

/**
 * Vérification SMS du téléphone du compte et des numéros Mobile Money, depuis la page de commande.
 */
final class PhoneAndMobileMoneyTest extends AppTestCase
{
    // ------------------------------------------------------------------ Téléphone du compte

    public function testPhoneVerificationFullFlow(): void
    {
        $user = $this->createUser('c@example.com');
        $this->signIn($user);

        $r = $this->api('POST', '/paiement/telephone/envoyer', ['phone' => '034 12 345 67']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('sent', $r['state']);
        self::assertSame('034 •• ••• 67', $r['maskedTarget']);
        self::assertSame(1, $this->otp()->count(OtpPurpose::PhoneVerification));

        // Le numéro est enregistré mais PAS encore vérifié.
        $fresh = $this->reload($user);
        self::assertSame('+261341234567', $fresh->getPhone());
        self::assertFalse($fresh->isPhoneVerified());

        // Code faux puis juste.
        $r = $this->api('POST', '/paiement/telephone/verifier', ['code' => '000000']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_code', $r['error']);
        self::assertSame(4, $r['attemptsLeft']);

        $r = $this->api('POST', '/paiement/telephone/verifier', ['code' => $this->otp()->lastCode(OtpPurpose::PhoneVerification)]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('verified', $r['state']);
        self::assertTrue($this->reload($user)->isPhoneVerified());
    }

    public function testInvalidAndLandlinePhoneNumbersAreRejected(): void
    {
        $this->signIn($this->createUser('c@example.com'));

        foreach (['abc', '020 22 123 45', '0341234'] as $bad) {
            $r = $this->api('POST', '/paiement/telephone/envoyer', ['phone' => $bad]);
            self::assertSame(422, $this->httpStatus(), $bad);
            self::assertSame('invalid_phone', $r['error']);
        }
        self::assertSame(0, $this->otp()->count());
    }

    public function testEmptyPhoneWithoutStoredNumberIsRefused(): void
    {
        $this->signIn($this->createUser('c@example.com'));

        $r = $this->api('POST', '/paiement/telephone/envoyer', ['phone' => '']);
        self::assertSame('phone_required', $r['error']);
    }

    public function testStoredPhoneCanBeVerifiedWithoutRetyping(): void
    {
        $this->signIn($this->createUser('c@example.com', phone: '+261331234567'));

        $r = $this->api('POST', '/paiement/telephone/envoyer', []);
        self::assertSame('sent', $r['state']);
        self::assertSame('+261331234567', $this->otp()->sent[0]['target']);
    }

    public function testAlreadyVerifiedPhoneNeedsNoSms(): void
    {
        $this->signIn($this->createUser('c@example.com', phone: '+261341234567', phoneVerified: true));

        $r = $this->api('POST', '/paiement/telephone/envoyer', ['phone' => '0341234567']);
        self::assertSame('verified', $r['state']);
        self::assertSame(0, $this->otp()->count());
    }

    public function testVerifiedPhoneCannotBeReplacedFromCheckout(): void
    {
        $user = $this->createUser('c@example.com', phone: '+261341234567', phoneVerified: true);
        $this->signIn($user);

        $r = $this->api('POST', '/paiement/telephone/envoyer', ['phone' => '033 99 888 77']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('phone_change_forbidden', $r['error']);
        self::assertSame('+261341234567', $this->reload($user)->getPhone());
    }

    public function testPhoneAlreadyVerifiedByAnotherAccountIsRefusedOnlyAfterProofOfPossession(): void
    {
        $this->createUser('owner@example.com', phone: '+261341234567', phoneVerified: true);
        $other = $this->createUser('other@example.com');
        $this->signIn($other);

        // L'envoi du code ne révèle rien (même réponse que pour un numéro libre)…
        $r = $this->api('POST', '/paiement/telephone/envoyer', ['phone' => '034 12 345 67']);
        self::assertSame('sent', $r['state']);

        // …et seule la preuve de possession (le bon code) mène au refus « déjà pris ».
        $r = $this->api('POST', '/paiement/telephone/verifier', ['code' => $this->otp()->lastCode(OtpPurpose::PhoneVerification)]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('phone_taken', $r['error']);
        self::assertFalse($this->reload($other)->isPhoneVerified());
    }

    public function testResendTooSoonIsRefusedWithCountdown(): void
    {
        $this->signIn($this->createUser('c@example.com'));
        $this->api('POST', '/paiement/telephone/envoyer', ['phone' => '0341234567']);

        $r = $this->api('POST', '/paiement/telephone/envoyer', ['phone' => '0341234567']);
        self::assertSame(429, $this->httpStatus());
        self::assertSame('too_soon', $r['error']);
        self::assertGreaterThan(0, $r['retryAfter']);
        self::assertSame(1, $this->otp()->count());
    }

    // ------------------------------------------------------------------ Mobile Money

    public function testMobileMoneyRulesAreEnforcedWithPreciseMessages(): void
    {
        $this->signIn($this->createUser('c@example.com', phone: '+261341234567', phoneVerified: true));

        $cases = [
            '134 12 345 67' => 'Le numéro doit débuter par 03x ou +261',
            '034 12 345' => 'Le numéro doit comporter 10 chiffres',
            '031 12 345 67' => 'Opérateur Mobile Money non reconnu',
        ];
        foreach ($cases as $number => $fragment) {
            $r = $this->api('POST', '/paiement/mobile-money/envoyer', ['number' => $number]);
            self::assertSame(422, $this->httpStatus(), $number);
            self::assertSame('mobile_money_invalid', $r['error']);
            self::assertStringContainsString($fragment, $r['message'], $number);
        }
    }

    public function testNewMobileMoneyNumberMustBeVerifiedBySms(): void
    {
        $user = $this->createUser('c@example.com', phone: '+261341234567', phoneVerified: true);
        $this->signIn($user);

        $r = $this->api('POST', '/paiement/mobile-money/envoyer', ['number' => '032 11 222 33']);
        self::assertSame('sent', $r['state']);
        self::assertSame('032 •• ••• 33', $r['maskedTarget']);
        // Le SMS part vers LE NUMÉRO MOBILE MONEY, pas vers le téléphone du compte.
        self::assertSame('+261321122233', $this->otp()->sent[0]['target']);
        self::assertSame(0, $this->em()->getRepository(MobileMoneyAccount::class)->count([]));

        // Mauvais code : rien n'est enregistré.
        $this->api('POST', '/paiement/mobile-money/verifier', ['number' => '032 11 222 33', 'code' => '111111']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(0, $this->em()->getRepository(MobileMoneyAccount::class)->count([]));

        // Bon code : numéro enregistré comme vérifié, opérateur déduit du préfixe.
        $r = $this->api('POST', '/paiement/mobile-money/verifier', ['number' => '032 11 222 33', 'code' => $this->otp()->lastCode(OtpPurpose::PaymentPhone)]);
        self::assertSame('verified', $r['state']);
        self::assertSame('orange_money', $r['carrier']);

        $accounts = $this->em()->getRepository(MobileMoneyAccount::class)->findAll();
        self::assertCount(1, $accounts);
        self::assertTrue($accounts[0]->isVerified());
        self::assertSame('+261321122233', $accounts[0]->getMsisdn());

        // La fois suivante, plus de SMS.
        $r = $this->api('POST', '/paiement/mobile-money/envoyer', ['number' => '0321122233']);
        self::assertSame('verified', $r['state']);
        self::assertSame(1, $this->otp()->count(OtpPurpose::PaymentPhone));
    }

    public function testCodeSentToOneNumberCannotValidateAnother(): void
    {
        $this->signIn($this->createUser('c@example.com', phone: '+261341234567', phoneVerified: true));
        $this->api('POST', '/paiement/mobile-money/envoyer', ['number' => '032 11 222 33']);
        $code = $this->otp()->lastCode(OtpPurpose::PaymentPhone);

        $r = $this->api('POST', '/paiement/mobile-money/verifier', ['number' => '033 44 555 66', 'code' => $code]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('code_expired', $r['error']);
        self::assertSame(0, $this->em()->getRepository(MobileMoneyAccount::class)->count([]));
    }

    public function testOwnVerifiedPhoneIsTrustedAsMobileMoneyNumberWithoutSms(): void
    {
        $this->signIn($this->createUser('c@example.com', phone: '+261341234567', phoneVerified: true));

        $r = $this->api('POST', '/paiement/mobile-money/envoyer', ['number' => '+261 34 12 345 67']);
        self::assertSame('verified', $r['state']);
        self::assertSame('mvola', $r['carrier']);
        self::assertSame(0, $this->otp()->count());
        self::assertSame(1, $this->em()->getRepository(MobileMoneyAccount::class)->count([]));
    }

    public function testMobileMoneyNumbersAreCapped(): void
    {
        $user = $this->createUser('c@example.com', phone: '+261341234567', phoneVerified: true);
        $now = new \DateTimeImmutable();
        foreach (['0321000001', '0321000002', '0321000003', '0321000004', '0321000005'] as $n) {
            $account = new MobileMoneyAccount($this->em()->getRepository(User::class)->find($user->getId()), \App\Enum\MobileMoneyCarrier::OrangeMoney, '+261'.substr($n, 1), $now);
            $account->markVerified($now);
            $this->em()->persist($account);
        }
        $this->em()->flush();
        $this->em()->clear();
        $this->signIn($user);

        $r = $this->api('POST', '/paiement/mobile-money/envoyer', ['number' => '033 11 111 11']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('too_many_numbers', $r['error']);
    }

    // ------------------------------------------------------------------ Accès

    public function testVisitorGetsA401(): void
    {
        $this->api('POST', '/paiement/telephone/envoyer', ['phone' => '0341234567']);
        self::assertSame(401, $this->httpStatus());
        self::assertSame(0, $this->otp()->count());
    }

    public function testAdminAndSupplierAccountsCannotUseCheckoutEndpoints(): void
    {
        $this->csrfToken(); // jeton obtenu avant la connexion simulée
        foreach ([AccountType::Admin, AccountType::Supplier] as $type) {
            $account = $this->createUser($type->value.'@example.com', $type);
            $this->client->loginUser($this->em()->getRepository(User::class)->find($account->getId()), 'main');

            $this->api('POST', '/paiement/telephone/envoyer', ['phone' => '0341234567']);
            self::assertSame(403, $this->httpStatus(), $type->value);
            self::assertSame(0, $this->otp()->count());
        }
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $this->signIn($this->createUser('c@example.com'));

        $this->api('POST', '/paiement/telephone/envoyer', ['phone' => '0341234567'], withCsrf: false);
        self::assertSame(403, $this->httpStatus());
        self::assertSame(0, $this->otp()->count());
    }
}
