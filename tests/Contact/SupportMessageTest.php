<?php

namespace App\Tests\Contact;

use App\Contact\SupportChannel;
use App\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Messages écrits au support depuis le site (contact, réclamation, candidature, avis) : POST /support/message.
 *
 * Le message part par e-mail vers GOBUY_SUPPORT_EMAIL (support@gobuy.test dans .env.test). Rien n'est affiché comme
 * « envoyé » si rien n'est parti : ces tests vérifient l'e-mail réellement produit.
 */
final class SupportMessageTest extends AppTestCase
{
    /** @return array<string, mixed> */
    private function contactBody(array $override = []): array
    {
        return $override + [
            'kind' => 'contact',
            'name' => 'Haja Rasolofo',
            'contact' => 'haja@example.com',
            'message' => 'Bonjour, où en est ma demande ?',
        ];
    }

    public function testVisitorContactMessageReachesTheSupportInbox(): void
    {
        $r = $this->api('POST', '/support/message', $this->contactBody());

        self::assertSame(200, $this->httpStatus());
        self::assertTrue($r['ok']);
        // Le navigateur de test annonce l'anglais par défaut (en-tête Accept-Language) : la réponse est donc en anglais.
        self::assertSame('Your message has been sent to our support team!', $r['message']);

        self::assertEmailCount(1);
        $mail = self::getMailerMessage(0);
        self::assertEmailHeaderSame($mail, 'To', 'support@gobuy.test');
        self::assertEmailSubjectContains($mail, '[GoBuy] Message');
        // Une adresse e-mail laissée par le visiteur devient l'adresse de réponse : l'équipe répond d'un clic.
        self::assertEmailHeaderSame($mail, 'Reply-To', 'haja@example.com');
        self::assertEmailTextBodyContains($mail, 'Haja Rasolofo');
        self::assertEmailTextBodyContains($mail, 'Compte GoBuy : visiteur non connecté');
        self::assertEmailTextBodyContains($mail, 'où en est ma demande');
        // Texte brut uniquement : aucune version HTML qui pourrait contenir du code injecté.
        self::assertNull($mail->getHtmlBody());
    }

    public function testMessageFromASignedInClientCarriesTheAccountId(): void
    {
        $user = $this->createUser();
        $this->signIn($user);

        $this->api('POST', '/support/message', $this->contactBody(['contact' => '034 12 345 67']));

        self::assertSame(200, $this->httpStatus());
        $mail = self::getMailerMessage(0);
        self::assertEmailTextBodyContains($mail, $user->getId()->toRfc4122());
        // Contact = téléphone : pas d'adresse de réponse.
        self::assertFalse($mail->getHeaders()->has('Reply-To'));
    }

    public function testFeedbackIsAnonymousWithAnOptionalMood(): void
    {
        $this->api('POST', '/support/message', ['kind' => 'feedback', 'mood' => 'happy', 'message' => 'Très bon service, merci !']);

        self::assertSame(200, $this->httpStatus());
        $mail = self::getMailerMessage(0);
        self::assertEmailSubjectContains($mail, '[GoBuy] Avis');
        self::assertEmailTextBodyContains($mail, 'Humeur : Satisfait');
    }

    public function testClaimNeedsAnOrderNumberAndAReasonAndMentionsThemInTheSubject(): void
    {
        $r = $this->api('POST', '/support/message', $this->contactBody(['kind' => 'claim']));
        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('order', $r['errors']);
        self::assertArrayHasKey('reason', $r['errors']);
        self::assertEmailCount(0);

        $this->api('POST', '/support/message', $this->contactBody(['kind' => 'claim', 'order' => 'GB-2026-482915', 'reason' => 'delay']));
        self::assertSame(200, $this->httpStatus());
        $mail = self::getMailerMessage(0);
        self::assertEmailSubjectContains($mail, 'Réclamation — commande GB-2026-482915');
        self::assertEmailTextBodyContains($mail, 'Motif : Retard important de livraison');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): array
    {
        return [
            'type inconnu' => [['kind' => 'spam', 'message' => 'Un message assez long'], 'kind'],
            'message vide' => [['kind' => 'feedback', 'message' => ''], 'message'],
            'message trop court' => [['kind' => 'feedback', 'message' => 'ok'], 'message'],
            'message trop long' => [['kind' => 'feedback', 'message' => str_repeat('a', 2001)], 'message'],
            'humeur inconnue' => [['kind' => 'feedback', 'mood' => 'furious', 'message' => 'Un message assez long'], 'mood'],
            'nom manquant' => [['kind' => 'contact', 'contact' => 'a@example.com', 'message' => 'Un message assez long'], 'name'],
            'contact manquant' => [['kind' => 'contact', 'name' => 'Haja', 'message' => 'Un message assez long'], 'contact'],
            'contact ni e-mail ni téléphone' => [['kind' => 'contact', 'name' => 'Haja', 'contact' => 'pas un contact', 'message' => 'Un message assez long'], 'contact'],
            'e-mail invalide' => [['kind' => 'contact', 'name' => 'Haja', 'contact' => 'haja@', 'message' => 'Un message assez long'], 'contact'],
            'candidature sans nom' => [['kind' => 'job', 'contact' => 'a@example.com', 'message' => 'Un message assez long'], 'name'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidMessagesAreRefusedFieldByField(array $body, string $field): void
    {
        $r = $this->api('POST', '/support/message', $body);

        self::assertSame(422, $this->httpStatus());
        self::assertFalse($r['ok']);
        self::assertArrayHasKey($field, $r['errors']);
        self::assertEmailCount(0);
    }

    public function testErrorMessagesAreTranslatedInTheRequestLanguage(): void
    {
        $this->client->request('POST', '/support/message', [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_ACCEPT_LANGUAGE' => 'en', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
        ], json_encode(['kind' => 'feedback', 'message' => ''], \JSON_THROW_ON_ERROR));
        $r = (array) json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('Please check the highlighted fields and try again.', $r['message']);
        self::assertSame('This field is required.', $r['errors']['message'][0]);
    }

    public function testControlCharactersInTheOrderNumberCannotInjectMailHeaders(): void
    {
        $this->api('POST', '/support/message', $this->contactBody([
            'kind' => 'claim', 'reason' => 'other', 'order' => "GB-1\r\nBcc: victime@example.com",
        ]));

        self::assertSame(200, $this->httpStatus());
        $mail = self::getMailerMessage(0);
        self::assertFalse($mail->getHeaders()->has('Bcc'));
        self::assertDoesNotMatchRegularExpression('/[\r\n]/', (string) $mail->getSubject());
    }

    public function testRequestsWithoutTheCsrfTokenAreRefused(): void
    {
        $this->api('POST', '/support/message', $this->contactBody(), withCsrf: false);

        self::assertSame(403, $this->httpStatus());
        self::assertEmailCount(0);
    }

    public function testSendingIsLimitedToFivePerHourPerIp(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->api('POST', '/support/message', $this->contactBody());
            self::assertSame(200, $this->httpStatus(), 'envoi n° '.$i);
        }

        $r = $this->api('POST', '/support/message', $this->contactBody());
        self::assertSame(429, $this->httpStatus());
        self::assertSame('rate_limited', $r['error']);
        self::assertNotEmpty($this->client->getResponse()->headers->get('Retry-After'));
        self::assertEmailCount(0); // la requête refusée n'a rien envoyé

        // Une autre adresse IP n'est pas concernée.
        $this->api('POST', '/support/message', $this->contactBody(), ip: '198.51.100.9');
        self::assertSame(200, $this->httpStatus());
    }

    public function testNonJsonBodyIsABadRequest(): void
    {
        $this->client->request('POST', '/support/message', [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
        ], '{pas du json');

        self::assertSame(400, $this->httpStatus());
    }

    // ------------------------------------------------------------------ Canal de support

    /** @return array<string, array{string, ?string}> */
    public static function channelValues(): array
    {
        return [
            'adresse valide' => ['support@gobuy.mg', 'support@gobuy.mg'],
            'espaces autour' => ['  support@gobuy.mg ', 'support@gobuy.mg'],
            'vide' => ['', null],
            'invalide' => ['pas-un-email', null],
            'retour à la ligne (injection)' => ["support@gobuy.mg\r\nBcc: x@y.z", null],
        ];
    }

    #[DataProvider('channelValues')]
    public function testSupportChannelOnlyAcceptsAValidAddress(string $configured, ?string $expected): void
    {
        $channel = new SupportChannel($configured);

        self::assertSame($expected, $channel->email());
        self::assertSame(null !== $expected, $channel->isEnabled());
    }

    // ------------------------------------------------------------------ Formulaires du site

    public function testFormsAreOfferedBecauseTheSupportAddressIsConfigured(): void
    {
        $crawler = $this->client->request('GET', '/');

        $kinds = $crawler->filter('form[data-support-form]')->each(static fn ($f): string => (string) $f->attr('data-support-form'));
        sort($kinds);
        self::assertSame(['claim', 'contact', 'feedback', 'job'], $kinds);
        // Chaque formulaire a sa zone d'erreur et ne contient aucun champ qui ne soit pas lu par le serveur.
        self::assertCount(4, $crawler->filter('form[data-support-form] [data-support-error]'));
        self::assertStringContainsString('mailto:support@gobuy.test', (string) $this->client->getResponse()->getContent());
    }
}
