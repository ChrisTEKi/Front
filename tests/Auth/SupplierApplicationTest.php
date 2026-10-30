<?php

namespace App\Tests\Auth;

use App\Auth\SupplierApplication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le nettoyage des réponses de l'assistant « Devenir partenaire » : ce que le navigateur envoie n'est jamais cru sur parole.
 */
final class SupplierApplicationTest extends TestCase
{
    public function testAValidApplicationIsKeptAsIs(): void
    {
        $input = [
            'contactRole' => 'Gérante', 'city' => 'Antananarivo', 'activityType' => 'company', 'taxId' => '1234567890', 'mainCategory' => 'cat-mode', 'plan' => 'pro',
            'sector' => 'Textile', 'productTypes' => ['fashion', 'home'], 'skuRange' => '11-50', 'prepTime' => '24_48h', 'salesPoints' => ['shop', 'market'],
            'ownDelivery' => false, 'stockTool' => 'Excel', 'damage' => true, 'proofs' => ['photos'], 'expectations' => 'Plus de clients.',
        ];

        self::assertEquals($input, SupplierApplication::sanitize($input));
    }

    public function testUnknownKeysAndUnknownCodesAreDropped(): void
    {
        $clean = SupplierApplication::sanitize([
            'isAdmin' => true, 'status' => 'approved', 'roles' => ['ROLE_ADMIN'],
            'activityType' => 'government', 'mainCategory' => 'cat-armes', 'plan' => 'gratuit', 'skuRange' => '9999', 'prepTime' => 'jamais',
            'productTypes' => ['fashion', 'weapons', 42, ['x']], 'salesPoints' => ['shop', 'shop', 'moon'], 'proofs' => 'photos',
            'city' => 'Toamasina',
        ]);

        self::assertSame(['city' => 'Toamasina', 'productTypes' => ['fashion'], 'salesPoints' => ['shop']], $clean);
    }

    /** @return array<string, array{mixed}> */
    public static function notAnApplication(): array
    {
        return ['null' => [null], 'string' => ['texte'], 'number' => [42], 'bool' => [true], 'empty array' => [[]], 'only junk' => [['foo' => 'bar']]];
    }

    #[DataProvider('notAnApplication')]
    public function testAnythingElseGivesNull(mixed $raw): void
    {
        self::assertNull(SupplierApplication::sanitize($raw));
    }

    public function testFreeTextsAreCleanedAndTruncated(): void
    {
        $clean = SupplierApplication::sanitize([
            'city' => "  Fianar\x00antsoa\x07  ",
            'expectations' => str_repeat('é', 1500),
            'sector' => ['tableau'],       // mauvais type : ignoré
            'stockTool' => 12345,          // mauvais type : ignoré
            'taxId' => "   \x01   ",       // vide après nettoyage : ignoré
        ]);

        self::assertSame('Fianarantsoa', $clean['city']);
        self::assertSame(1000, mb_strlen($clean['expectations']));
        self::assertArrayNotHasKey('sector', $clean);
        self::assertArrayNotHasKey('stockTool', $clean);
        self::assertArrayNotHasKey('taxId', $clean);
    }

    public function testFlagsMustBeRealBooleans(): void
    {
        // « "true" », 1 ou "yes" ne sont pas acceptés : seul le vrai booléen JSON l'est (pas d'interprétation hasardeuse).
        $clean = SupplierApplication::sanitize(['ownDelivery' => 'true', 'damage' => 1, 'city' => 'x']);

        self::assertSame(['city' => 'x'], $clean);
    }
}
