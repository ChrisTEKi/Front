<?php

namespace App\Social;

use App\Enum\SocialProvider;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client OpenID Connect (le protocole derrière « Se connecter avec Google / Apple »), écrit à la main pour rester
 * léger et lisible. Déroulement (« authorization code flow » + PKCE) :
 *
 *   1. start()    : on prépare l'adresse d'autorisation du fournisseur ; l'utilisateur s'y rend et s'identifie CHEZ LUI
 *                   (nous ne voyons jamais son mot de passe Google/Apple) ;
 *   2. le fournisseur le renvoie chez nous avec un « code » à usage unique ;
 *   3. exchange() : on échange ce code (serveur à serveur, avec notre secret) contre un « jeton d'identité » signé,
 *                   puis on VÉRIFIE ce jeton : signature (clés publiques du fournisseur), émetteur, destinataire,
 *                   expiration et « nonce ».
 *
 * Protections : `state` (lie le retour à la session de l'utilisateur → anti-CSRF), `nonce` (lie le jeton à CETTE
 * demande → anti-rejeu), PKCE `code_verifier` (un code volé est inutilisable sans lui — Google).
 */
final class OidcClient
{
    private const ENDPOINTS = [
        'google' => [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'jwks' => 'https://www.googleapis.com/oauth2/v3/certs',
            'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
        ],
        'apple' => [
            'authorize' => 'https://appleid.apple.com/auth/authorize',
            'token' => 'https://appleid.apple.com/auth/token',
            'jwks' => 'https://appleid.apple.com/auth/keys',
            'issuers' => ['https://appleid.apple.com'],
        ],
    ];

    /** Durée de conservation des clés publiques du fournisseur en cache. */
    private const JWKS_TTL = 3600;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly SocialConfig $config,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
    ) {
    }

    /** URL de retour déclarée chez le fournisseur (doit correspondre EXACTEMENT à celle enregistrée dans sa console). */
    public function redirectUri(SocialProvider $provider): string
    {
        return $this->urls->generate('social_callback', ['provider' => $provider->value], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Prépare une demande de connexion.
     *
     * @return array{url: string, state: string, nonce: string, verifier: string} `state`, `nonce` et `verifier` sont à garder en session
     */
    public function start(SocialProvider $provider): array
    {
        $state = $this->randomToken();
        $nonce = $this->randomToken();
        $verifier = $this->randomToken(48); // PKCE : secret de 64 caractères environ

        $params = [
            'client_id' => $this->config->clientId($provider),
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'state' => $state,
            'nonce' => $nonce,
        ];

        if (SocialProvider::Google === $provider) {
            $params['scope'] = 'openid email profile';
            // PKCE : on n'envoie que l'EMPREINTE du secret ; le secret lui-même ne part qu'à l'échange du code.
            $params['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $params['code_challenge_method'] = 'S256';
            $params['prompt'] = 'select_account';
        } else {
            $params['scope'] = 'name email';
            // Apple renvoie le résultat par un POST « form_post » (voir SocialAuthController::callback).
            $params['response_mode'] = 'form_post';
        }

        return [
            'url' => self::ENDPOINTS[$provider->value]['authorize'].'?'.http_build_query($params, '', '&', \PHP_QUERY_RFC3986),
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
        ];
    }

    /**
     * Échange le code contre le jeton d'identité et le vérifie. Retourne l'identité garantie.
     *
     * @throws SocialAuthException
     */
    public function exchange(SocialProvider $provider, string $code, string $verifier, string $expectedNonce): SocialClaims
    {
        $endpoints = self::ENDPOINTS[$provider->value];

        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri($provider),
            'client_id' => $this->config->clientId($provider),
        ];
        if (SocialProvider::Google === $provider) {
            $body['client_secret'] = $this->config->googleClientSecret;
            $body['code_verifier'] = $verifier;
        } else {
            $body['client_secret'] = $this->appleClientSecret();
        }

        try {
            $response = $this->http->request('POST', $endpoints['token'], ['body' => $body, 'timeout' => 10]);
            $data = $response->toArray(); // lève une exception si le code HTTP n'est pas 2xx
        } catch (\Throwable $e) {
            throw new SocialAuthException('provider_unreachable', 'Échange du code refusé : '.$e->getMessage(), $e);
        }

        $idToken = $data['id_token'] ?? null;
        if (!\is_string($idToken) || '' === $idToken) {
            throw new SocialAuthException('invalid_response', 'Réponse sans id_token.');
        }

        $payload = $this->decodeAndVerify($provider, $idToken);

        // --- Vérifications des « claims » (contenu du jeton) ---
        if (!\in_array($payload['iss'] ?? null, $endpoints['issuers'], true)) {
            throw new SocialAuthException('invalid_response', 'Émetteur (iss) inattendu.');
        }
        $audience = (array) ($payload['aud'] ?? []);
        if (!\in_array($this->config->clientId($provider), $audience, true)) {
            throw new SocialAuthException('invalid_response', 'Destinataire (aud) inattendu.');
        }
        if (!isset($payload['nonce']) || !\is_string($payload['nonce']) || !hash_equals($expectedNonce, $payload['nonce'])) {
            throw new SocialAuthException('invalid_response', 'Nonce invalide (rejeu possible).');
        }
        $subject = $payload['sub'] ?? null;
        if (!\is_string($subject) || '' === $subject) {
            throw new SocialAuthException('invalid_response', 'Identifiant (sub) absent.');
        }

        $email = isset($payload['email']) && \is_string($payload['email']) ? mb_strtolower(trim($payload['email'])) : null;
        // Google envoie un booléen, Apple parfois la CHAÎNE « true » : on accepte les deux.
        $verified = filter_var($payload['email_verified'] ?? false, \FILTER_VALIDATE_BOOLEAN);
        $name = isset($payload['name']) && \is_string($payload['name']) ? trim($payload['name']) : null;

        return new SocialClaims($provider, $subject, $email, $verified, $name);
    }

    /**
     * Décode le jeton en vérifiant sa SIGNATURE avec les clés publiques du fournisseur (et son expiration).
     * Si la clé de signature est inconnue (le fournisseur vient de la renouveler), on recharge la liste une fois.
     *
     * @return array<string, mixed>
     */
    private function decodeAndVerify(SocialProvider $provider, string $idToken): array
    {
        JWT::$leeway = 60; // tolère 60 s de décalage d'horloge entre serveurs

        foreach ([false, true] as $forceRefresh) {
            try {
                $keys = JWK::parseKeySet($this->jwks($provider, $forceRefresh), 'RS256');

                return (array) JWT::decode($idToken, $keys);
            } catch (\UnexpectedValueException $e) {
                // « kid » introuvable (clé renouvelée) : on retente avec une liste fraîche ; sinon on abandonne.
                if ($forceRefresh) {
                    throw new SocialAuthException('invalid_response', 'Jeton invalide : '.$e->getMessage(), $e);
                }
            } catch (\Throwable $e) {
                throw new SocialAuthException('invalid_response', 'Jeton invalide : '.$e->getMessage(), $e);
            }
        }

        throw new SocialAuthException('invalid_response', 'Jeton invalide.');
    }

    /**
     * Clés publiques du fournisseur (JWKS), mises en cache 1 h.
     *
     * @return array<string, mixed>
     */
    private function jwks(SocialProvider $provider, bool $forceRefresh): array
    {
        $key = 'oidc_jwks_'.$provider->value;
        if ($forceRefresh) {
            $this->cache->delete($key);
        }

        return $this->cache->get($key, function (ItemInterface $item) use ($provider): array {
            $item->expiresAfter(self::JWKS_TTL);

            try {
                return $this->http->request('GET', self::ENDPOINTS[$provider->value]['jwks'], ['timeout' => 10])->toArray();
            } catch (\Throwable $e) {
                throw new SocialAuthException('provider_unreachable', 'Clés publiques injoignables : '.$e->getMessage(), $e);
            }
        });
    }

    /**
     * Apple n'utilise pas de secret fixe : le « client_secret » est un petit jeton (JWT) que NOUS signons avec la clé
     * privée téléchargée sur le compte développeur Apple, valable quelques minutes.
     */
    private function appleClientSecret(): string
    {
        $privateKey = (string) @file_get_contents($this->config->applePrivateKeyPath);
        if ('' === $privateKey) {
            throw new SocialAuthException('not_configured', 'Clé privée Apple illisible.');
        }

        $now = $this->clock->now()->getTimestamp();

        return JWT::encode([
            'iss' => $this->config->appleTeamId,
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => 'https://appleid.apple.com',
            'sub' => $this->config->appleClientId,
        ], $privateKey, 'ES256', $this->config->appleKeyId);
    }

    private function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
