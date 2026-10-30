<?php

namespace App\Tests\Ui;

use App\Tests\Support\AppTestCase;

/** En-têtes de sécurité envoyés avec chaque réponse (voir SecurityHeadersSubscriber). */
final class SecurityHeadersTest extends AppTestCase
{
    public function testContentSecurityPolicyRestrictsFramesBaseAndFormTargets(): void
    {
        $this->client->request('GET', '/');
        $headers = $this->client->getResponse()->headers;

        $csp = (string) $headers->get('Content-Security-Policy');
        foreach (["frame-ancestors 'none'", "base-uri 'self'", "object-src 'none'", "form-action 'self'"] as $directive) {
            self::assertStringContainsString($directive, $csp, $directive);
        }
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
    }
}
