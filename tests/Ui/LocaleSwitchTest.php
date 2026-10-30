<?php

namespace App\Tests\Ui;

use App\Entity\User;
use App\Tests\Support\AppTestCase;

/**
 * Sélecteur de langue (/langue/{fr|en|es}) : lien simple en GET, donc appelable depuis un AUTRE site.
 * La langue du compte (celle des e-mails et SMS) n'est enregistrée en base que si le clic vient d'une page de GoBuy.
 */
final class LocaleSwitchTest extends AppTestCase
{
    private function storedLocale(User $user): string
    {
        $this->em()->clear();

        return $this->em()->getRepository(User::class)->find($user->getId())->getLocale();
    }

    public function testClickFromAnotherSiteOrWithoutRefererDoesNotChangeTheAccountLanguage(): void
    {
        $user = $this->createUser();
        $this->signIn($user);

        // Sans Referer (lien ouvert depuis un message, un favori, ou Referer supprimé) : affichage seulement.
        $this->client->request('GET', '/langue/en');
        self::assertResponseRedirects('/');
        self::assertSame('fr', $this->storedLocale($user));

        // Depuis un site tiers.
        $this->client->request('GET', '/langue/es', server: ['HTTP_REFERER' => 'https://autre-site.example/page']);
        self::assertResponseRedirects('/');
        self::assertSame('fr', $this->storedLocale($user));

        // Le choix reste mémorisé pour l'affichage (session) : la page suivante est bien dans cette langue.
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertSame('es', $this->client->getCrawler()->filter('html')->attr('lang'));
    }

    public function testClickFromAGoBuyPageIsSavedOnTheAccount(): void
    {
        $user = $this->createUser();
        $this->signIn($user);

        $this->client->request('GET', '/langue/en', server: ['HTTP_REFERER' => 'http://localhost/compte']);

        self::assertResponseRedirects('/compte');
        self::assertSame('en', $this->storedLocale($user));
    }
}
