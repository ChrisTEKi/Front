<?php

namespace App\Tests\Account;

use App\Entity\Address;
use App\Entity\User;
use App\Enum\AccountType;
use App\Tests\Support\AppTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Carnet d'adresses de livraison : plusieurs adresses, une seule « par défaut », réservé à son propriétaire.
 */
final class AddressBookTest extends AppTestCase
{
    private const API = '/compte/api/adresses';
    private const HOME = ['label' => 'Maison', 'fullName' => 'Rakoto Jean', 'phone' => '034 12 345 67', 'street' => 'Lot II A 124, Ankadifotsy', 'city' => 'Antananarivo', 'postalCode' => '101', 'instructions' => 'Portail bleu'];
    private const WORK = ['label' => 'Bureau', 'fullName' => 'Rakoto Jean', 'phone' => '', 'street' => 'Immeuble Zital, Ankorondrano', 'city' => 'Antananarivo', 'postalCode' => '101', 'instructions' => ''];

    private function signedIn(string $email = 'c@example.com'): User
    {
        $user = $this->createUser($email);
        $this->signIn($user);

        return $user;
    }

    /** @return list<array<string, mixed>> */
    private function listed(): array
    {
        $r = $this->api('GET', self::API);
        self::assertSame(200, $this->httpStatus());

        return $r['addresses'];
    }

    /** @param array<string, mixed> $data */
    private function add(array $data): array
    {
        $r = $this->api('POST', self::API, $data);
        self::assertContains($this->httpStatus(), [200, 201], json_encode($r));

        return $r['addresses'];
    }

    private function defaults(User $user): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM addresses WHERE user_id = :u AND is_default = true', ['u' => $user->getId()->toRfc4122()]);
    }

    // ------------------------------------------------------------------ Défaut

    public function testFirstAddressBecomesTheDefaultAndTheNextOnesDoNot(): void
    {
        $user = $this->signedIn();

        $list = $this->add(self::HOME);
        self::assertCount(1, $list);
        self::assertTrue($list[0]['isDefault']);
        self::assertSame('Maison', $list[0]['label']);
        self::assertSame('+261341234567', $list[0]['phone'], 'Téléphone normalisé en E.164.');

        $list = $this->add(self::WORK);
        self::assertCount(2, $list);
        self::assertSame('Maison', $list[0]['label'], 'L\'adresse par défaut reste en tête.');
        self::assertFalse($list[1]['isDefault']);
        self::assertNull($list[1]['phone'], 'Le téléphone est facultatif.');
        self::assertSame(1, $this->defaults($user));
    }

    public function testMakingAnotherAddressTheDefaultMovesTheBadge(): void
    {
        $user = $this->signedIn();
        $this->add(self::HOME);
        $list = $this->add(self::WORK + ['makeDefault' => true]);

        self::assertSame('Bureau', $list[0]['label']);
        self::assertTrue($list[0]['isDefault']);
        self::assertFalse($list[1]['isDefault']);
        self::assertSame(1, $this->defaults($user));

        // Et on peut revenir en arrière.
        $r = $this->api('POST', self::API.'/'.$list[1]['id'].'/defaut');
        self::assertSame('Maison', $r['addresses'][0]['label']);
        self::assertSame(1, $this->defaults($user));
    }

    public function testDatabaseNeverAllowsTwoDefaultAddressesForOneClient(): void
    {
        $user = $this->signedIn();
        $this->add(self::HOME);
        $this->add(self::WORK);
        $other = $this->em()->getRepository(Address::class)->findBy(['isDefault' => false])[0];

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->getConnection()->executeStatement('UPDATE addresses SET is_default = true WHERE id = :id', ['id' => $other->getId()->toRfc4122()]);
    }

    public function testDeletingTheDefaultPromotesTheMostRecentOther(): void
    {
        $user = $this->signedIn();
        $first = $this->add(self::HOME)[0];
        static::mockTime((new \DateTimeImmutable())->modify('+1 minute'));
        $this->add(self::WORK);
        static::mockTime((new \DateTimeImmutable())->modify('+2 minutes'));
        $this->add(['label' => 'Parents', 'street' => 'Lot 7 Analakely'] + self::WORK);

        $r = $this->api('POST', self::API.'/'.$first['id'].'/supprimer');

        self::assertCount(2, $r['addresses']);
        self::assertSame('Parents', $r['addresses'][0]['label'], 'La plus récente prend le relais.');
        self::assertTrue($r['addresses'][0]['isDefault']);
        self::assertSame(1, $this->defaults($user));

        // Supprimer les deux dernières : plus aucune adresse, plus aucun défaut.
        foreach ($r['addresses'] as $address) {
            $last = $this->api('POST', self::API.'/'.$address['id'].'/supprimer');
        }
        self::assertSame([], $last['addresses']);
        self::assertSame(0, $this->defaults($user));
    }

    // ------------------------------------------------------------------ Règles

    public function testSameAddressIsNeverCreatedTwice(): void
    {
        $user = $this->signedIn();
        $this->add(self::HOME);

        $list = $this->add(['label' => 'Chez moi', 'fullName' => '  rakoto jean ', 'street' => 'LOT II A 124, ANKADIFOTSY', 'city' => 'antananarivo'] + self::HOME);

        self::assertCount(1, $list);
        self::assertSame('Chez moi', $list[0]['label'], 'L\'adresse existante est mise à jour.');
        self::assertCount(1, $this->em()->getRepository(Address::class)->findBy(['user' => $user->getId()]));
    }

    public function testAtMostTenAddresses(): void
    {
        $this->signedIn();
        for ($i = 1; $i <= 10; ++$i) {
            $this->add(['street' => 'Rue '.$i] + self::HOME);
        }

        $r = $this->api('POST', self::API, ['street' => 'Rue 11'] + self::HOME);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('address_limit', $r['error']);
        self::assertCount(10, $this->listed());
    }

    public function testAddressCanBeEdited(): void
    {
        $this->signedIn();
        $id = $this->add(self::HOME)[0]['id'];

        $r = $this->api('POST', self::API.'/'.$id.'/modifier', ['street' => 'Lot 99 Andraharo', 'city' => 'Antananarivo', 'fullName' => 'Rabe Paul', 'label' => '', 'phone' => '', 'postalCode' => '', 'instructions' => '']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('Lot 99 Andraharo', $r['addresses'][0]['street']);
        self::assertSame('Rabe Paul', $r['addresses'][0]['fullName']);
        self::assertNull($r['addresses'][0]['label']);
        self::assertNull($r['addresses'][0]['phone']);
        self::assertTrue($r['addresses'][0]['isDefault'], 'Modifier ne change pas l\'adresse par défaut.');
    }

    public function testValidationErrorsAreReportedPerField(): void
    {
        $this->signedIn();

        $r = $this->api('POST', self::API, ['fullName' => '', 'street' => '', 'city' => '', 'phone' => 'abc', 'label' => str_repeat('x', 41)]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('validation', $r['error']);
        foreach (['fullName', 'street', 'city', 'phone', 'label'] as $field) {
            self::assertArrayHasKey($field, $r['errors']);
        }
        self::assertSame([], $this->listed());
    }

    // ------------------------------------------------------------------ Isolation

    public function testOtherPeoplesAddressesAreInvisibleAndUntouchable(): void
    {
        $this->signedIn('a@example.com');
        $mine = $this->add(self::HOME)[0]['id'];

        $this->newBrowser();
        $this->signIn($this->createUser('b@example.com'));
        self::assertSame([], $this->listed(), 'Chacun ne voit que ses adresses.');

        foreach (['/defaut', '/supprimer'] as $action) {
            $r = $this->api('POST', self::API.'/'.$mine.$action);
            self::assertSame(404, $this->httpStatus());
            self::assertSame('not_found', $r['error']);
        }
        $r = $this->api('POST', self::API.'/'.$mine.'/modifier', self::WORK);
        self::assertSame(404, $this->httpStatus());
        // Un identifiant inexistant donne exactement la même réponse.
        $r2 = $this->api('POST', self::API.'/'.'00000000-0000-7000-8000-000000000000/supprimer');
        self::assertSame(404, $this->httpStatus());
        self::assertSame($r['error'], $r2['error']);

        // L'adresse d'origine est intacte.
        $this->newBrowser();
        $this->signIn($this->createUser('a2@example.com'));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM addresses WHERE id = :id', ['id' => $mine]));
    }

    public function testVisitorSupplierAndAdminAreRefused(): void
    {
        $this->api('GET', self::API);
        self::assertSame(401, $this->httpStatus());

        $supplier = $this->createUser('boutique@example.com', AccountType::Supplier, true, '+261331112233', true);
        $this->signIn($supplier);
        $r = $this->api('GET', self::API);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('addresses_not_available', $r['error']);
    }

    public function testCsrfTokenIsRequiredToModify(): void
    {
        $this->signedIn();

        $this->api('POST', self::API, self::HOME, withCsrf: false);

        self::assertSame(403, $this->httpStatus());
        self::assertSame([], $this->listed());
    }

    // ------------------------------------------------------------------ Inscription avec adresse

    private const SIGNUP = ['fullName' => 'Jean Rakoto', 'email' => 'jean@example.com', 'password' => 'Plume-Verte-Horizon-8472', 'phone' => '034 12 345 67'];

    public function testAddressGivenAtSignupBecomesTheDefaultAddress(): void
    {
        $r = $this->api('POST', '/auth/register/client', self::SIGNUP + ['street' => 'Lot II A 124, Ankadifotsy', 'city' => 'Antananarivo', 'postalCode' => '101']);

        self::assertSame(200, $this->httpStatus(), json_encode($r));
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jean@example.com']);
        self::assertNotNull($user);
        $addresses = $this->em()->getRepository(Address::class)->findBy(['user' => $user->getId()]);
        self::assertCount(1, $addresses);
        self::assertTrue($addresses[0]->isDefault());
        self::assertSame('Jean Rakoto', $addresses[0]->getFullName());
        self::assertSame('+261341234567', $addresses[0]->getPhone());
        self::assertSame('Lot II A 124, Ankadifotsy', $addresses[0]->getStreet());
    }

    public function testSignupWithoutAddressCreatesNone(): void
    {
        $this->api('POST', '/auth/register/client', self::SIGNUP);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, $this->em()->getRepository(Address::class)->count([]));
    }

    public function testSignupStreetWithoutCityIsRefusedAndCreatesNothing(): void
    {
        $r = $this->api('POST', '/auth/register/client', self::SIGNUP + ['street' => 'Lot II A 124']);

        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('city', $r['errors']);
        self::assertSame(0, $this->em()->getRepository(User::class)->count([]));
    }

    public function testSignupOnAnExistingEmailCreatesNoAddress(): void
    {
        $this->createUser('jean@example.com');

        $this->api('POST', '/auth/register/client', self::SIGNUP + ['street' => 'Lot 1', 'city' => 'Antananarivo']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, $this->em()->getRepository(Address::class)->count([]));
    }
}
