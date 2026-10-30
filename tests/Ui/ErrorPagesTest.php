<?php

namespace App\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Les pages d'erreur (404, 403, autres) s'affichent dans les trois langues, sans clé brute et sans détail technique.
 * (En production, Symfony les utilise à la place de la page de debug ; on les rend ici directement avec Twig.)
 */
final class ErrorPagesTest extends KernelTestCase
{
    /** @return array<string, array{string, string, int, string}> */
    public static function pages(): array
    {
        $cases = [];
        $titles = [
            'error404' => [404, ['fr' => 'Page introuvable', 'en' => 'Page not found', 'es' => 'Página no encontrada']],
            'error403' => [403, ['fr' => 'Accès refusé', 'en' => 'Access denied', 'es' => 'Acceso denegado']],
            'error' => [500, ['fr' => 'Une erreur est survenue', 'en' => 'Something went wrong', 'es' => 'Ha ocurrido un error']],
        ];
        foreach ($titles as $template => [$code, $byLocale]) {
            foreach ($byLocale as $locale => $title) {
                $cases[$template.' '.$locale] = [$template, $locale, $code, $title];
            }
        }

        return $cases;
    }

    #[DataProvider('pages')]
    public function testErrorPageIsTranslated(string $template, string $locale, int $code, string $expectedTitle): void
    {
        self::bootKernel();
        $request = Request::create('/inexistant');
        $request->setLocale($locale);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        // En vraie requête, Symfony aligne le traducteur sur la langue de la requête (LocaleSubscriber puis LocaleAwareListener) ; ici on le fait à la main.
        self::getContainer()->get('translator')->setLocale($locale);

        $html = self::getContainer()->get('twig')->render('@Twig/Exception/'.$template.'.html.twig', ['status_code' => $code, 'status_text' => 'x', 'exception' => new \RuntimeException('secret-detail-technique')]);

        self::assertStringContainsString('lang="'.$locale.'"', $html);
        self::assertStringContainsString($expectedTitle, $html);
        self::assertStringContainsString((string) $code, $html);
        // Jamais de clé de traduction brute ni de détail technique de l'exception.
        self::assertDoesNotMatchRegularExpression('/(?<![\w\/.#-])error\.[a-z_]+(\.[a-z_]+)?(?![\w-])/', (string) preg_replace('#<(script|style)\b.*?</\1>#si', '', $html));
        self::assertStringNotContainsString('secret-detail-technique', $html);
        // Un lien de retour vers l'accueil existe toujours.
        self::assertMatchesRegularExpression('#<a [^>]*href="/"#', $html);
    }
}
