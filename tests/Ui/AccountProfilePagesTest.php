<?php

namespace App\Tests\Ui;

use App\Entity\User;
use App\Enum\AccountType;
use App\Tests\Support\AppTestCase;

/**
 * Pages « Mon compte » : photo, coordonnées (double confirmation), carnet d'adresses, et formulaire d'inscription.
 */
final class AccountProfilePagesTest extends AppTestCase
{
    public function testInfoPageOffersPhotoAndContactChangeForAVerifiedPhone(): void
    {
        $user = $this->createUser('c@example.com', phone: '+261341234567', phoneVerified: true);
        $this->signIn($user);

        $crawler = $this->client->request('GET', '/compte?section=info');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-avatar-panel] [data-avatar-input]'));
        self::assertCount(1, $crawler->filter('[data-contact-open="email"]'));
        self::assertCount(1, $crawler->filter('[data-contact-open="phone"]'));
        self::assertCount(1, $crawler->filter('[data-contact-open="phone_remove"]'));
        self::assertCount(1, $crawler->filter('form[data-contact]'));
        // Numéro vérifié : plus de formulaire « léger » (il ne sert qu'à ajouter ou corriger un numéro non vérifié).
        self::assertCount(0, $crawler->filter('[data-account-form="phone"]'));
    }

    public function testInfoPageKeepsTheLightPhoneFormForAnUnverifiedNumber(): void
    {
        $this->signIn($this->createUser('c@example.com', phone: '+261341234567'));

        $crawler = $this->client->request('GET', '/compte?section=info');

        self::assertCount(1, $crawler->filter('[data-account-form="phone"]'));
        self::assertCount(0, $crawler->filter('[data-contact-open="phone"]'));
        self::assertCount(1, $crawler->filter('[data-contact-open="email"]'), 'L\'e-mail se change toujours avec la double confirmation.');
    }

    public function testSupplierCannotRemoveButCanChangeTheirPhone(): void
    {
        $this->signIn($this->createUser('boutique@example.com', AccountType::Supplier, true, '+261331112233', true));

        $crawler = $this->client->request('GET', '/compte?section=info');

        self::assertCount(1, $crawler->filter('[data-contact-open="phone"]'));
        self::assertCount(0, $crawler->filter('[data-contact-open="phone_remove"]'));
        self::assertCount(0, $crawler->filter('[data-account-section="addresses"]'), 'Pas de carnet d\'adresses pour un fournisseur.');
    }

    public function testPageTextsAreTranslated(): void
    {
        $this->signIn($this->createUser('c@example.com', phone: '+261341234567', phoneVerified: true));

        foreach (['fr' => 'Photo de profil', 'en' => 'Profile photo', 'es' => 'Foto de perfil'] as $locale => $expected) {
            $this->client->request('GET', '/langue/'.$locale); // le choix de langue est mémorisé dans le compte
            $this->client->request('GET', '/compte?section=info');
            self::assertSelectorTextContains('[data-avatar-panel]', $expected);
            self::assertStringNotContainsString('account.photo', (string) $this->client->getResponse()->getContent(), 'Aucune clé de traduction brute dans la page ('.$locale.').');
        }
    }

    public function testAddressesPageListsCardsWithTheDefaultBadge(): void
    {
        $this->signIn($this->createUser('c@example.com'));
        $this->api('POST', '/compte/api/adresses', ['fullName' => 'Rakoto', 'street' => 'Lot 1', 'city' => 'Antananarivo', 'label' => 'Maison']);
        $this->api('POST', '/compte/api/adresses', ['fullName' => 'Rakoto', 'street' => 'Lot 2', 'city' => 'Antananarivo', 'label' => 'Bureau']);

        $crawler = $this->client->request('GET', '/compte?section=addresses');

        self::assertCount(2, $crawler->filter('[data-address-card]'));
        self::assertStringContainsString('Maison', $crawler->filter('[data-address-card]')->first()->text(), 'La défaut est en tête.');
        self::assertCount(1, $crawler->filter('[data-address-card] [data-address-delete]:not([disabled])')->reduce(static fn ($n, $i): bool => 0 === $i), 'Boutons présents.');
        self::assertCount(1, $crawler->filter('[data-address-default]'), 'Seule l\'adresse qui n\'est pas la défaut propose « Définir par défaut ».');
        self::assertCount(1, $crawler->filter('form[data-address-form-edit]'));
    }

    public function testHeaderAndAccountShowThePhotoOnceUploaded(): void
    {
        $user = $this->createUser('c@example.com');
        $this->signIn($user);

        $crawler = $this->client->request('GET', '/compte');
        self::assertCount(0, $crawler->filter('img[src^="/profil/photo"]'), 'Pas de photo : l\'initiale est affichée.');

        $img = imagecreatetruecolor(60, 60);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $path = tempnam(sys_get_temp_dir(), 'gobuy-avatar-');
        file_put_contents($path, $png);
        $this->client->request('POST', '/compte/api/photo', [], ['photo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'p.png', 'image/png', null, true)], ['HTTP_X_CSRF_TOKEN' => $this->csrfToken()]);
        @unlink($path);
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/compte');
        self::assertGreaterThanOrEqual(2, $crawler->filter('img[src^="/profil/photo?v="]')->count(), 'En-tête et carte du compte.');
    }

    public function testSignupFormOffersAnOptionalAddress(): void
    {
        $crawler = $this->client->request('GET', '/connexion?mode=register');

        self::assertResponseIsSuccessful();
        foreach (['street', 'city', 'postalCode'] as $name) {
            self::assertCount(1, $crawler->filter('form[data-auth-form="register_client"] details [name="'.$name.'"]'), $name);
        }
    }

    public function testVisitorGetsNoAddressOrContactApi(): void
    {
        $this->api('GET', '/compte/api/adresses');
        self::assertSame(401, $this->httpStatus());
        self::assertNull($this->em()->getRepository(User::class)->findOneBy([]));
    }
}
