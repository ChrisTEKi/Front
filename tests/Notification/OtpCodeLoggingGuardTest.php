<?php

namespace App\Tests\Notification;

use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Notification\MailerSmsOtpDelivery;
use App\Notification\TransactionalMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * APP_OTP_LOG_CODES=1 est la valeur par défaut de .env. Les codes à usage unique ne doivent être écrits en clair dans les
 * journaux que dans les environnements « dev » et « test » : jamais sur un serveur « staging », « preprod » ou « prod ».
 */
final class OtpCodeLoggingGuardTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev', true];
        yield 'test' => ['test', true];
        yield 'staging' => ['staging', false];
        yield 'preprod' => ['preprod', false];
        yield 'prod' => ['prod', false];
    }

    #[DataProvider('environments')]
    public function testCodesAreLoggedOnlyInDevAndTest(string $environment, bool $expectedLogged): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expectedLogged ? self::once() : self::never())->method('warning');

        $delivery = new MailerSmsOtpDelivery(
            (new \ReflectionClass(TransactionalMailer::class))->newInstanceWithoutConstructor(), // inutilisé : on envoie un SMS
            $this->createStub(TexterInterface::class),
            $this->createStub(TranslatorInterface::class),
            new LocaleSwitcher('fr', []),
            $this->createStub(UrlGeneratorInterface::class),
            $logger,
            new MockClock('2026-10-26 10:00:00'),
            true, // APP_OTP_LOG_CODES=1
            $environment,
        );

        $now = new \DateTimeImmutable('2026-10-26 10:00:00');
        $user = User::registerClient('a@example.com', 'A', '+261341234567', 'fr', $now);
        $delivery->deliver($user, OtpPurpose::TwoFactor, OtpChannel::Sms, '+261341234567', '123456', $now->modify('+10 minutes'));
    }
}
