<?php

namespace App\Tests\Support;

use Firebase\JWT\JWT;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Faux fournisseur d'identité (Google/Apple) pour les tests : il signe de VRAIS jetons avec une clé RSA de test
 * et répond aux appels « échange du code » et « clés publiques » sans aucun accès Internet.
 */
final class FakeOidcProvider
{
    private const KID = 'test-key-1';

    /** @var \OpenSSLAsymmetricKey */
    private $key;

    /** Claims à signer lors du prochain échange de code (le test les prépare). */
    public ?array $claims = null;

    /** Simule une panne du fournisseur lors de l'échange du code. */
    public bool $tokenEndpointFails = false;

    /** @var list<array{method: string, url: string, body: mixed}> */
    public array $requests = [];

    public function __construct()
    {
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]) ?: throw new \RuntimeException('Clé de test impossible à générer.');
    }

    public function httpClient(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];
            $json = ['response_headers' => ['content-type' => 'application/json']];

            if (str_contains($url, '/token')) {
                if ($this->tokenEndpointFails || null === $this->claims) {
                    return new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400] + $json);
                }

                return new MockResponse(json_encode(['id_token' => $this->sign($this->claims), 'access_token' => 'unused']), $json);
            }

            // Clés publiques (JWKS), au format attendu par Firebase\JWT\JWK.
            $details = openssl_pkey_get_details($this->key);

            return new MockResponse(json_encode(['keys' => [[
                'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => self::KID,
                'n' => $this->b64($details['rsa']['n']), 'e' => $this->b64($details['rsa']['e']),
            ]]]), $json);
        });
    }

    /** Signe des claims avec la clé de test (peut aussi servir à fabriquer un jeton FAUX, signé par une autre clé). */
    public function sign(array $claims, ?\OpenSSLAsymmetricKey $withKey = null): string
    {
        openssl_pkey_export($withKey ?? $this->key, $pem);

        return JWT::encode($claims, $pem, 'RS256', self::KID);
    }

    /**
     * Claims « normaux » d'un jeton Google valide.
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function googleClaims(string $nonce, array $override = []): array
    {
        return $override + [
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client',
            'sub' => 'google-sub-1',
            'email' => 'jean@example.com',
            'email_verified' => true,
            'name' => 'Jean Rakoto',
            'nonce' => $nonce,
            'iat' => time(),
            'exp' => time() + 600,
        ];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function appleClaims(string $nonce, array $override = []): array
    {
        return $override + [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'test-apple-service',
            'sub' => 'apple-sub-1',
            'email' => 'jean@example.com',
            'email_verified' => 'true', // Apple envoie parfois une chaîne
            'nonce' => $nonce,
            'iat' => time(),
            'exp' => time() + 600,
        ];
    }

    public static function newKey(): \OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]) ?: throw new \RuntimeException('Clé impossible à générer.');
    }

    private function b64(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
