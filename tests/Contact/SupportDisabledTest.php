<?php

namespace App\Tests\Contact;

use App\Tests\Support\AppTestCase;

/**
 * Sans adresse de support configurée (GOBUY_SUPPORT_EMAIL vide), le site n'affiche aucun formulaire qui n'enverrait rien
 * et l'API refuse proprement les envois.
 */
final class SupportDisabledTest extends AppTestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        // La variable doit être vidée AVANT le démarrage du noyau (parent::setUp crée le navigateur de test).
        // Valeur d'origine (chargée depuis .env.test) : à remettre en place à la fin pour ne pas perturber les autres tests.
        $this->previous = isset($_SERVER['GOBUY_SUPPORT_EMAIL']) ? (string) $_SERVER['GOBUY_SUPPORT_EMAIL'] : null;
        $this->setSupportEmail('');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->setSupportEmail($this->previous);
        parent::tearDown();
    }

    private function setSupportEmail(?string $value): void
    {
        if (null === $value) {
            unset($_SERVER['GOBUY_SUPPORT_EMAIL'], $_ENV['GOBUY_SUPPORT_EMAIL']);
            putenv('GOBUY_SUPPORT_EMAIL');

            return;
        }
        $_SERVER['GOBUY_SUPPORT_EMAIL'] = $_ENV['GOBUY_SUPPORT_EMAIL'] = $value;
        putenv('GOBUY_SUPPORT_EMAIL='.$value);
    }

    public function testNoFormIsDisplayedWithoutASupportAddress(): void
    {
        $crawler = $this->client->request('GET', '/');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->httpStatus());
        self::assertCount(0, $crawler->filter('form[data-support-form]'));
        self::assertCount(0, $crawler->filter('#feedback-drawer, [data-drawer-toggle="feedback-drawer"]'));
        self::assertStringNotContainsString('mailto:', $html);
        // Le visiteur lit une explication honnête plutôt qu'un formulaire muet.
        self::assertStringContainsString('Support contact details will be published soon.', $html);
        self::assertStringContainsString('The form is not available at the moment.', $html);
    }

    public function testTheApiRefusesToSendWithoutASupportAddress(): void
    {
        $r = $this->api('POST', '/support/message', ['kind' => 'feedback', 'message' => 'Un message assez long']);

        self::assertSame(503, $this->httpStatus());
        self::assertSame('unavailable', $r['error']);
        self::assertEmailCount(0);
    }
}
