<?php

namespace App\Tests\Phone;

use App\Enum\MobileMoneyCarrier;
use App\Phone\MobileMoneyNumberValidator;
use App\Phone\PhoneNumberParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberParserTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function inputs(): iterable
    {
        yield 'national avec espaces' => ['034 12 345 67', '+261341234567'];
        yield 'national compact' => ['0341234567', '+261341234567'];
        yield 'international +261' => ['+261 34 12 345 67', '+261341234567'];
        yield 'international 00261' => ['00261341234567', '+261341234567'];
        yield 'sans plus 261' => ['261341234567', '+261341234567'];
        yield 'tirets et points' => ['034-12.345-67', '+261341234567'];
        yield 'étranger France' => ['+33 6 12 34 56 78', '+33612345678'];
        yield 'fixe malgache refusé' => ['020 22 123 45', null];
        yield 'trop court' => ['034 12 345', null];
        yield 'trop long' => ['034 12 345 678', null];
        yield 'lettres' => ['03412abc67', null];
        yield 'vide' => ['', null];
        yield 'injection' => ['+261341234567; DROP TABLE users', null];
    }

    #[DataProvider('inputs')]
    public function testParse(string $input, ?string $expected): void
    {
        $phone = (new PhoneNumberParser())->parse($input);
        self::assertSame($expected, $phone?->e164);
    }

    public function testDisplayAndMask(): void
    {
        $phone = (new PhoneNumberParser())->parse('0341234567');
        self::assertNotNull($phone);
        self::assertSame('034 12 345 67', $phone->display());
        self::assertSame('034 •• ••• 67', $phone->masked());
        self::assertSame('0341234567', $phone->national());
        self::assertSame(MobileMoneyCarrier::Mvola, $phone->mobileMoneyCarrier());
    }

    /** @return iterable<string, array{string, bool, ?MobileMoneyCarrier}> */
    public static function momo(): iterable
    {
        yield 'mvola 034' => ['034 12 345 67', true, MobileMoneyCarrier::Mvola];
        yield 'mvola 038' => ['0381234567', true, MobileMoneyCarrier::Mvola];
        yield 'airtel 033' => ['033 12 345 67', true, MobileMoneyCarrier::AirtelMoney];
        yield 'orange 032' => ['032 12 345 67', true, MobileMoneyCarrier::OrangeMoney];
        yield 'orange 037' => ['+261 37 12 345 67', true, MobileMoneyCarrier::OrangeMoney];
        yield 'préfixe inconnu 036' => ['036 12 345 67', false, null];
        yield 'trop court' => ['034 12 345', false, null];
        yield 'ne commence pas par 0' => ['134 12 345 67', false, null];
    }

    #[DataProvider('momo')]
    public function testMobileMoney(string $input, bool $valid, ?MobileMoneyCarrier $carrier): void
    {
        $result = (new MobileMoneyNumberValidator())->validate($input);
        self::assertSame($valid, $result->valid);
        self::assertSame($carrier, $result->carrier);
    }
}
