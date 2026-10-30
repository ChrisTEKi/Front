<?php

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou du Kernel : hors « dev » et « test », on refuse de démarrer avec le secret d'exemple de .env (ou un secret trop court).
 * Un serveur « staging » joignable depuis Internet est donc protégé comme la production.
 */
final class KernelSecretGuardTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /** @return iterable<string, array{string}> */
    public static function exposedEnvironments(): iterable
    {
        yield 'prod' => ['prod'];
        yield 'staging' => ['staging'];
        yield 'preprod' => ['preprod'];
    }

    #[DataProvider('exposedEnvironments')]
    public function testWeakSecretIsRefusedOutsideDevAndTest(string $environment): void
    {
        foreach (['changeme_generate_a_real_secret', 'trop-court', ''] as $weak) {
            $_SERVER['APP_SECRET'] = $weak;
            try {
                (new Kernel($environment, false))->boot();
                self::fail('Le démarrage aurait dû être refusé pour « '.$weak.' » en '.$environment.'.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('APP_SECRET', $e->getMessage());
            }
        }
    }
}
