<?php

namespace App\Tests\Ui;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Garde-fou des traductions : les trois langues (fr, en, es) ont exactement les mêmes clés et les mêmes
 * « %variables% », et chaque clé citée dans un gabarit Twig ou dans un script JavaScript existe bien.
 * (Une clé manquante s'afficherait telle quelle à l'écran, par exemple « users.off ».)
 */
final class TranslationCatalogTest extends TestCase
{
    private const LOCALES = ['fr', 'en', 'es'];

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return array<string, array<string, array<string, string>>> domaine => langue => clé pointée => texte */
    private static function catalogs(): array
    {
        $all = [];
        foreach (glob(self::root().'/translations/*.yaml') ?: [] as $file) {
            [$domain, $locale] = explode('.', basename($file, '.yaml'), 2);
            $all[$domain][$locale] = self::flatten(Yaml::parseFile($file) ?? []);
        }

        return $all;
    }

    /** @param array<array-key, mixed> $tree @return array<string, string> */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            $path = $prefix.$key;
            if (\is_array($value)) {
                $out += self::flatten($value, $path.'.');
            } else {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }

    public function testEveryLanguageHasTheSameKeysAndTheSamePlaceholders(): void
    {
        foreach (self::catalogs() as $domain => $languages) {
            self::assertEqualsCanonicalizing(self::LOCALES, array_keys($languages), 'Langues du domaine « '.$domain.' ».');
            $keys = array_keys($languages['fr']);
            foreach (self::LOCALES as $locale) {
                self::assertEqualsCanonicalizing($keys, array_keys($languages[$locale]), sprintf('Clés différentes entre fr et %s (domaine « %s »).', $locale, $domain));
            }
            foreach ($keys as $key) {
                $expected = $this->placeholders($languages['fr'][$key]);
                foreach (['en', 'es'] as $locale) {
                    self::assertSame($expected, $this->placeholders($languages[$locale][$key]), sprintf('Variables différentes pour « %s.%s » (fr / %s).', $domain, $key, $locale));
                }
            }
        }
    }

    public function testEveryKeyUsedInATwigTemplateExists(): void
    {
        $catalogs = self::catalogs();
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $default = preg_match("/trans_default_domain\\s+'(\\w+)'/", $source, $m) ? $m[1] : 'messages';
            preg_match_all("/'([a-z0-9_.]+)'\\s*\\|\\s*trans\\b(\\(((?:[^()]|\\([^()]*\\))*)\\))?/", $source, $matches, \PREG_SET_ORDER);
            foreach ($matches as $match) {
                $domain = $default;
                if (isset($match[3]) && preg_match("/'(\\w+)'\\s*$/", trim($match[3]), $d) && isset($catalogs[$d[1]])) {
                    $domain = $d[1];
                }
                if (!isset($catalogs[$domain]['fr'][$match[1]])) {
                    $missing[] = sprintf('%s : %s (%s)', substr($file->getPathname(), \strlen(self::root()) + 1), $match[1], $domain);
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)), 'Clés de traduction introuvables dans les gabarits.');
    }

    public function testEveryKeyUsedByAScriptExistsInTheJavascriptDictionary(): void
    {
        $dictionary = self::catalogs()['js']['fr'];
        $missing = [];
        foreach (glob(self::root().'/assets/*.js') ?: [] as $file) {
            preg_match_all("/\\bt\\(\\s*['\"]([a-z0-9_]+(?:\\.[a-z0-9_]+)+)['\"]/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                if (!isset($dictionary[$key])) {
                    $missing[] = basename($file).' : '.$key;
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)), 'Clés absentes du dictionnaire JavaScript (translations/js.*.yaml).');
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/%[a-z_]+%/i', $text, $found);
        $names = array_unique($found[0]);
        sort($names);

        return array_values($names);
    }
}
