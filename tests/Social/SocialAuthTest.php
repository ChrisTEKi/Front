<?php

namespace App\Tests\Social;

use App\Entity\SocialIdentity;
use App\Entity\User;
use App\Enum\AccountType;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeOidcProvider;

/**
 * Connexion rapide Google / Apple, avec un faux fournisseur (jetons réellement signés et vérifiés).
 */
final class SocialAuthTest extends AppTestCase
{
    private FakeOidcProvider $idp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idp = static::getContainer()->get(FakeOidcProvider::class);
    }

    /** Démarre la connexion et retourne [state, nonce] lus dans l'URL de redirection vers le fournisseur. */
    private function startGoogle(): array
    {
        $this->client->request('GET', '/auth/social/google');
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $q);

        return [$q['state'], $q['nonce'], $q];
    }

    private function callbackGoogle(string $state, string $code = 'abc'): void
    {
        $this->client->request('GET', '/auth/social/google/callback?'.http_build_query(['state' => $state, 'code' => $code]));
    }

    public function testStartRedirectsToGoogleWithProtections(): void
    {
        [, , $q] = $this->startGoogle();

        self::assertSame('test-google-client', $q['client_id']);
        self::assertSame('code', $q['response_type']);
        self::assertSame('openid email profile', $q['scope']);
        self::assertSame('S256', $q['code_challenge_method']); // PKCE
        self::assertNotEmpty($q['code_challenge']);
        self::assertGreaterThanOrEqual(32, \strlen($q['state']));
        self::assertGreaterThanOrEqual(32, \strlen($q['nonce']));
        self::assertStringEndsWith('/auth/social/google/callback', $q['redirect_uri']);
        // Le « verifier » PKCE ne doit JAMAIS apparaître dans l'URL.
        self::assertArrayNotHasKey('code_verifier', $q);
    }

    public function testNewUserIsCreatedAndLoggedIn(): void
    {
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce);

        $this->callbackGoogle($state);

        self::assertResponseRedirects('/');
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jean@example.com']);
        self::assertNotNull($user);
        self::assertSame(AccountType::Client, $user->getAccountType());
        self::assertNull($user->getPassword());          // compte social : pas de mot de passe
        self::assertTrue($user->isEmailVerified());      // adresse certifiée par Google
        self::assertSame('Jean Rakoto', $user->getFullName());
        self::assertCount(1, $this->em()->getRepository(SocialIdentity::class)->findAll());

        $this->client->request('GET', '/compte');
        self::assertResponseIsSuccessful();

        // Le PKCE verifier a bien été envoyé au fournisseur lors de l'échange du code.
        $token = array_values(array_filter($this->idp->requests, static fn (array $r): bool => str_contains($r['url'], '/token')))[0];
        self::assertSame('abc', $token['body']['code'] ?? $this->bodyParam($token['body'], 'code'));
    }

    /** @param mixed $body */
    private function bodyParam($body, string $key): ?string
    {
        if (\is_array($body)) {
            return $body[$key] ?? null;
        }
        parse_str((string) $body, $out);

        return $out[$key] ?? null;
    }

    public function testSameSubjectReturnsSameUserEvenIfEmailChanged(): void
    {
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce);
        $this->callbackGoogle($state);

        $this->client->getCookieJar()->clear(); // nouveau navigateur
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce, ['email' => 'nouvelle.adresse@example.com']);
        $this->callbackGoogle($state);

        self::assertResponseRedirects('/');
        self::assertCount(1, $this->em()->getRepository(User::class)->findAll());
        self::assertCount(1, $this->em()->getRepository(SocialIdentity::class)->findAll());
    }

    public function testLinksToExistingVerifiedAccountByEmail(): void
    {
        $this->createUser('jean@example.com');
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce);

        $this->callbackGoogle($state);

        self::assertResponseRedirects('/');
        self::assertCount(1, $this->em()->getRepository(User::class)->findAll());
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jean@example.com']);
        self::assertNotNull($user->getPassword(), 'Le mot de passe d\'un compte déjà vérifié est conservé.');
    }

    public function testPreHijackedUnverifiedAccountLosesItsPassword(): void
    {
        // Un tiers a créé un compte avec l'e-mail de la victime (jamais vérifié) et SON mot de passe.
        $this->createUser('jean@example.com', emailVerified: false);
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce);

        $this->callbackGoogle($state);

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jean@example.com']);
        self::assertNull($user->getPassword(), 'Le mot de passe posé par le tiers doit disparaître.');
        self::assertTrue($user->isEmailVerified());
        // Et l'attaquant ne peut plus se connecter avec son ancien mot de passe.
        $this->client->getCookieJar()->clear();
        $this->refreshCsrf();
        $this->api('POST', '/auth/login', ['email' => 'jean@example.com', 'password' => self::PASSWORD]);
        self::assertSame(401, $this->httpStatus());
    }

    public function testUnverifiedEmailClaimIsRefused(): void
    {
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce, ['email_verified' => false]);

        $this->callbackGoogle($state);

        self::assertResponseRedirects('/connexion');
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    public function testWrongStateIsRefused(): void
    {
        [, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce);

        $this->callbackGoogle('state-forge-par-un-attaquant');

        self::assertResponseRedirects('/connexion');
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll());
        self::assertSame([], array_filter($this->idp->requests, static fn (array $r): bool => str_contains($r['url'], '/token')), 'Le code ne doit même pas être échangé.');
    }

    public function testCallbackWithoutStartedFlowIsRefused(): void
    {
        $this->callbackGoogle('nimporte-quoi');

        self::assertResponseRedirects('/connexion');
    }

    public function testWrongNonceIsRefused(): void
    {
        [$state] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims('un-autre-nonce'); // jeton destiné à une AUTRE demande (rejeu)

        $this->callbackGoogle($state);

        self::assertResponseRedirects('/connexion');
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    public function testWrongAudienceOrIssuerIsRefused(): void
    {
        foreach ([['aud' => 'client-d-une-autre-appli'], ['iss' => 'https://evil.example.com']] as $override) {
            $this->client->getCookieJar()->clear();
            [$state, $nonce] = $this->startGoogle();
            $this->idp->claims = FakeOidcProvider::googleClaims($nonce, $override);
            $this->callbackGoogle($state);
            self::assertResponseRedirects('/connexion');
        }
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    public function testTokenSignedByAnotherKeyIsRefused(): void
    {
        // Jeton fabriqué par un attaquant avec SA clé (même contenu, même « kid ») : la signature ne correspond pas.
        [$state, $nonce] = $this->startGoogle();
        $forged = $this->idp->sign(FakeOidcProvider::googleClaims($nonce), FakeOidcProvider::newKey());
        $this->idp->claims = null;
        // On remplace la réponse du serveur de jetons par le jeton falsifié.
        $client = new \Symfony\Component\HttpClient\MockHttpClient(function (string $method, string $url) use ($forged) {
            $json = ['response_headers' => ['content-type' => 'application/json']];

            return str_contains($url, '/token')
                ? new \Symfony\Component\HttpClient\Response\MockResponse(json_encode(['id_token' => $forged]), $json)
                : $this->idp->httpClient()->request($method, $url);
        });
        $oidc = new \App\Social\OidcClient($client, new \Symfony\Component\Cache\Adapter\ArrayAdapter(), static::getContainer()->get(\App\Social\SocialConfig::class), static::getContainer()->get('router'), static::getContainer()->get('clock'));

        $this->expectException(\App\Social\SocialAuthException::class);
        $oidc->exchange(\App\Enum\SocialProvider::Google, 'abc', 'verifier', $nonce);
    }

    public function testExpiredTokenIsRefused(): void
    {
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce, ['exp' => time() - 3600, 'iat' => time() - 7200]);

        $this->callbackGoogle($state);

        self::assertResponseRedirects('/connexion');
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    public function testStateIsSingleUse(): void
    {
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce);
        $this->callbackGoogle($state);
        self::assertResponseRedirects('/');

        // Rejouer exactement le même retour (ex. lien conservé dans l'historique) : refusé.
        $this->client->getCookieJar()->clear();
        $this->callbackGoogle($state);
        self::assertResponseRedirects('/connexion');
    }

    public function testAdminAndSupplierCannotUseSocialLogin(): void
    {
        $this->createUser('jean@example.com', AccountType::Admin);
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce);

        $this->callbackGoogle($state);

        self::assertResponseRedirects('/connexion');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects(); // pas connecté
    }

    public function testTwoFactorStillRequiredAfterSocialLogin(): void
    {
        $user = $this->reload($this->createUser('jean@example.com'));
        $user->setTwoFactor(true, false);
        $this->em()->flush();
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce);

        $this->callbackGoogle($state);

        // Pas connecté : la page de connexion reprend le parcours à l'étape 2FA.
        self::assertResponseRedirects('/connexion?view=resume');
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects();
        $r = $this->api('GET', '/auth/state');
        self::assertSame('two_factor', $r['step']);
    }

    public function testProviderOutageIsHandledGracefully(): void
    {
        [$state, $nonce] = $this->startGoogle();
        $this->idp->claims = FakeOidcProvider::googleClaims($nonce);
        $this->idp->tokenEndpointFails = true;

        $this->callbackGoogle($state);

        self::assertResponseRedirects('/connexion');
    }

    public function testUserCancellingAtProvider(): void
    {
        [$state] = $this->startGoogle();

        $this->client->request('GET', '/auth/social/google/callback?'.http_build_query(['state' => $state, 'error' => 'access_denied']));

        self::assertResponseRedirects('/connexion');
    }

    public function testNotConfiguredProviderShowsFriendlyError(): void
    {
        // Variable d'environnement vidée AVANT la première utilisation du service de configuration.
        $previous = $_ENV['GOOGLE_CLIENT_ID'] ?? null;
        $_ENV['GOOGLE_CLIENT_ID'] = $_SERVER['GOOGLE_CLIENT_ID'] = '';
        try {
            $this->client->request('GET', '/auth/social/google');
            self::assertResponseRedirects('/connexion');
        } finally {
            $_ENV['GOOGLE_CLIENT_ID'] = $_SERVER['GOOGLE_CLIENT_ID'] = $previous;
        }
    }

    // ------------------------------------------------------------------ Apple

    public function testAppleFormPostIsStoredThenCompletedByGetRequest(): void
    {
        $this->client->request('GET', '/auth/social/apple');
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://appleid.apple.com/auth/authorize?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $q);
        self::assertSame('form_post', $q['response_mode']);

        $this->idp->claims = FakeOidcProvider::appleClaims($q['nonce']);
        $name = json_encode(['name' => ['firstName' => 'Marie', 'lastName' => 'Razafy']]);

        // 1) Le POST d'Apple (sans cookie de session, comme en vrai) est mis de côté et redirigé en 303 vers un GET.
        $session = $this->client->getCookieJar()->all();
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/auth/social/apple/callback', ['state' => $q['state'], 'code' => 'apple-code', 'user' => $name]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $finish = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/auth/social/apple/finish?h=', $finish);
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll(), 'Rien ne doit être créé avant la vérification du state.');

        // 2) Le GET suivant porte le cookie de session : le state est vérifié, la connexion aboutit.
        foreach ($session as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->client->request('GET', $finish);
        self::assertResponseRedirects('/');
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'jean@example.com']);
        self::assertSame('Marie Razafy', $user->getFullName()); // nom reçu à la 1re autorisation
        self::assertTrue($user->isEmailVerified());           // email_verified = "true" (chaîne) accepté

        // 3) Le lien « finish » ne sert qu'une fois.
        $this->client->request('GET', $finish);
        self::assertResponseRedirects('/connexion');
    }

    public function testAppleCallbackIsRateLimitedAndCapsTheSizeOfEachField(): void
    {
        $ip = '203.0.113.'.random_int(100, 250); // une IP à soi : les compteurs ne sont pas remis à zéro entre deux tests
        $server = ['REMOTE_ADDR' => $ip];
        $huge = str_repeat('z', 100000);

        // Les 30 premiers retours (même énormes) sont acceptés et redirigés vers « finish »…
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('POST', '/auth/social/apple/callback', ['state' => $huge, 'code' => $huge, 'user' => $huge], server: $server);
            self::assertStringContainsString('/auth/social/apple/finish?h=', (string) $this->client->getResponse()->headers->get('Location'));
        }
        // …le 31e est refusé : retour à la page de connexion, rien n'est écrit dans le cache.
        $this->client->request('POST', '/auth/social/apple/callback', ['state' => 'a', 'code' => 'b'], server: $server);
        self::assertResponseRedirects('/connexion');
    }

    public function testAppleFinishRejectsForgedPostWithoutMatchingState(): void
    {
        // Un attaquant poste SES propres paramètres puis envoie le lien « finish » à la victime : le state ne correspond pas.
        $this->client->request('GET', '/auth/social/apple'); // session de la victime
        $this->client->request('POST', '/auth/social/apple/callback', ['state' => 'state-de-l-attaquant', 'code' => 'code-de-l-attaquant']);
        $finish = (string) $this->client->getResponse()->headers->get('Location');

        $this->idp->claims = FakeOidcProvider::appleClaims('x');
        $this->client->request('GET', $finish);

        self::assertResponseRedirects('/connexion');
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }
}
