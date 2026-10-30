<?php

namespace App\Tests\Ui;

use App\Enum\AccountType;
use App\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Chaque page s'affiche, dans les trois langues, sans clé de traduction « brute » visible (ex. « header.login »).
 *
 * Quand une clé manque, Symfony affiche la clé elle-même : on les repère donc dans le HTML produit. Les pages sont
 * demandées comme le fait un navigateur : en-tête « Accept-Language » (détection automatique de la langue).
 */
final class PagesRenderTest extends AppTestCase
{
    private const LOCALES = ['fr', 'en', 'es'];

    /** Pages publiques (visiteur). */
    private const PUBLIC_PAGES = ['/', '/connexion', '/connexion?mode=register', '/connexion?mode=register_supplier', '/panier', '/rayons', '/devenir-partenaire'];

    /** @return array<string, array{string}> */
    public static function locales(): array
    {
        return array_combine(self::LOCALES, array_map(static fn (string $l): array => [$l], self::LOCALES));
    }

    /**
     * Texte brut d'une page : sans <script> ni <style> (les clés y sont normales : noms de fichiers, JSON…).
     */
    private function visibleHtml(): string
    {
        $html = (string) $this->client->getResponse()->getContent();

        return (string) preg_replace('#<(script|style)\b.*?</\1>#si', '', $html);
    }

    /** @return list<string> clés de traduction brutes trouvées dans le HTML */
    private function rawKeys(): array
    {
        preg_match_all('/(?<![\w\/.#-])(?:meta|header|footer|nav|account|cart|tracking|product|auth|common|checkout|vendor|home|dept|info|modal|support|categories|error|supplier|admin|order|status|payment)\.[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*(?![\w-])/', $this->visibleHtml(), $m);

        return array_values(array_unique($m[0]));
    }

    private function open(string $uri, string $locale): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT_LANGUAGE' => $locale]);
        self::assertLessThan(500, $this->httpStatus(), $uri.' : erreur serveur : '.mb_substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 600));
    }

    #[DataProvider('locales')]
    public function testPublicPagesRenderWithoutRawKeys(string $locale): void
    {
        foreach (self::PUBLIC_PAGES as $uri) {
            $this->open($uri, $locale);
            self::assertSame(200, $this->httpStatus(), $uri.' ('.$locale.')');
            self::assertSame($locale, $this->client->getCrawler()->filter('html')->attr('lang'), $uri.' : langue de la page');
            self::assertSame([], $this->rawKeys(), $uri.' ('.$locale.') contient des clés non traduites');
        }
    }

    #[DataProvider('locales')]
    public function testClientPagesRenderWithoutRawKeys(string $locale): void
    {
        $user = $this->createUser();
        $this->signIn($user);

        foreach (['/compte', '/compte?section=security', '/compte?section=info', '/compte?section=orders', '/suivi', '/suivi?number=482915', '/commande'] as $uri) {
            $this->open($uri, $locale);
            self::assertSame(200, $this->httpStatus(), $uri.' ('.$locale.')');
            self::assertSame([], $this->rawKeys(), $uri.' ('.$locale.') contient des clés non traduites');
        }
    }

    // ------------------------------------------------------------------ Aucun reste de français en EN / ES

    /**
     * Mots français qui n'existent ni en anglais ni en espagnol : s'ils apparaissent dans une page EN/ES, un texte est resté
     * écrit en dur dans un gabarit au lieu d'une clé de traduction. (Les mots ambigus — « pour », « les », « nos », « sur »… — sont exclus.)
     */
    private const FRENCH_ONLY_WORDS = ['votre', 'vos', 'avec', 'dans', 'une', 'des', 'aux', 'sont', 'cette', 'ces', 'livraison', 'panier', 'commande', 'commandes', 'connexion', 'rechercher', 'fermer', 'suivant', 'commencer', 'bienvenue', 'accueil', 'réclamation', 'candidature', 'retours'];

    /** @return list<string> mots français trouvés dans le texte visible de la page affichée */
    private function frenchWords(): array
    {
        // Texte visible : sans scripts, styles, ni attributs ; les balises sont remplacées par des espaces.
        $text = mb_strtolower((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#si', '', (string) $this->client->getResponse()->getContent()), '')) ?: ''));
        preg_match_all('/(?<![\p{L}\p{N}])('.implode('|', self::FRENCH_ONLY_WORDS).')(?![\p{L}\p{N}])/u', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** Attributs affichés (placeholder, title, aria-label, alt) : eux aussi doivent être traduits. */
    private function frenchInAttributes(): array
    {
        $found = [];
        $this->client->getCrawler()->filter('[placeholder],[title],[aria-label],[alt]')->each(function ($node) use (&$found): void {
            foreach (['placeholder', 'title', 'aria-label', 'alt'] as $attr) {
                $value = mb_strtolower((string) $node->attr($attr));
                if ('' !== $value && 1 === preg_match('/(?<![\p{L}\p{N}])('.implode('|', self::FRENCH_ONLY_WORDS).')(?![\p{L}\p{N}])/u', $value, $mm)) {
                    $found[] = $attr.'="'.$value.'"';
                }
            }
        });

        return array_values(array_unique($found));
    }

    #[DataProvider('foreignLocales')]
    public function testNoFrenchLeftoverOnPublicPagesInOtherLanguages(string $locale): void
    {
        // Pages publiques + un rayon du catalogue (lien trouvé sur la page « Rayons »).
        $this->open('/rayons', $locale);
        $department = $this->client->getCrawler()->filter('a[href^="/rayon/"]')->first()->attr('href');
        self::assertNotNull($department, 'la page Rayons doit proposer au moins un rayon');

        foreach (['/', '/rayons', $department, '/panier', '/connexion', '/connexion?mode=register', '/connexion?mode=register_supplier', '/devenir-partenaire', '/produit/inconnu'] as $uri) {
            $this->open($uri, $locale);
            // Les pages d'erreur (404) sont des pages comme les autres : traduites aussi.
            self::assertSame([], $this->frenchWords(), $uri.' ('.$locale.') : mots français dans le texte');
            self::assertSame([], $this->frenchInAttributes(), $uri.' ('.$locale.') : mots français dans les attributs');
        }
    }

    /** @return array<string, array{string}> */
    public static function foreignLocales(): array
    {
        return ['en' => ['en'], 'es' => ['es']];
    }

    // ------------------------------------------------------------------ Devenir partenaire

    #[DataProvider('locales')]
    public function testVendorPageIsFullyTranslated(string $locale): void
    {
        $this->open('/devenir-partenaire', $locale);
        $text = strip_tags($this->visibleHtml());

        $expected = ['fr' => 'Démarrer ma candidature', 'en' => 'Start my application', 'es' => 'Iniciar mi solicitud'];
        self::assertStringContainsString($expected[$locale], $text);
        foreach (array_diff_key($expected, [$locale => 1]) as $other) {
            self::assertStringNotContainsString($other, $text, 'Un texte d\'une autre langue est affiché en '.$locale);
        }
        if ('fr' !== $locale) {
            // Aucun reste de français dans les sections de la page (FAQ, simulateur, formulaire, solidarité).
            foreach (['Reconstruisons Ensemble', 'Questions fréquemment posées', 'Calculez vos revenus', 'Envoyer mon dossier', 'Rayon principal'] as $french) {
                self::assertStringNotContainsString($french, $text, $french.' ('.$locale.')');
            }
        }
    }

    public function testVendorFormIsWiredToTheRegistration(): void
    {
        $crawler = $this->client->request('GET', '/devenir-partenaire');
        self::assertSame(200, $this->httpStatus());

        self::assertSame('/auth/register/supplier', $crawler->filter('[data-vendor-page]')->attr('data-vendor-endpoint'));
        self::assertSame('/connexion?view=resume', $crawler->filter('[data-vendor-page]')->attr('data-vendor-next-url'));
        // Les champs obligatoires du serveur existent, nommés comme dans l'API, avec leur zone d'erreur.
        foreach (['fullName', 'email', 'phone', 'password', 'companyName', 'companyAddress'] as $field) {
            self::assertCount(1, $crawler->filter('[data-vendor-form] [name="'.$field.'"][required]'), $field);
            self::assertCount(1, $crawler->filter('[data-vendor-form] [data-field-error="'.$field.'"]'), $field);
        }
        self::assertSame('password', $crawler->filter('[name="password"]')->attr('type'));
        self::assertSame('new-password', $crawler->filter('[name="password"]')->attr('autocomplete'));
        // Plus d'écran « Dossier envoyé » factice : la suite passe par la vérification e-mail / téléphone.
        self::assertCount(0, $crawler->filter('[data-vendor-view="success"]'));
    }

    public function testNoInventedSupportNumberIsPublished(): void
    {
        // Sans numéro configuré (GOBUY_SUPPORT_WHATSAPP), aucun lien WhatsApp n'est affiché, ni sur la page ni dans le pied de page.
        foreach (['/', '/devenir-partenaire'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertStringNotContainsString('wa.me/', (string) $this->client->getResponse()->getContent(), $uri);
        }
    }

    public function testHeaderMenuDependsOnTheRole(): void
    {
        // Visiteur : bouton Connexion, pas de menu de compte.
        $this->open('/', 'fr');
        self::assertCount(0, $this->client->getCrawler()->filter('#user-menu'));
        self::assertGreaterThan(0, $this->client->getCrawler()->filter('header a[href="/connexion"]')->count());

        // Client : menu de compte avec « Mes commandes » et déconnexion (lien à jeton CSRF), sans espace pro.
        $this->signIn($this->createUser());
        $this->open('/', 'fr');
        $menu = $this->client->getCrawler()->filter('#user-menu');
        self::assertCount(1, $menu);
        self::assertStringContainsString('Mes commandes', $menu->text());
        self::assertStringNotContainsString('Espace Fournisseur', $menu->text());
        self::assertStringContainsString('/deconnexion?_csrf_token=', (string) $menu->filter('a[href*="/deconnexion"]')->attr('href'));
    }

    public function testSupplierAndAdminMenus(): void
    {
        $this->signIn($this->createUser('f@example.com', AccountType::Supplier, phoneVerified: true));
        $this->open('/', 'fr');
        $menu = $this->client->getCrawler()->filter('#user-menu')->text();
        self::assertStringContainsString('Espace Fournisseur', $menu);
        self::assertStringNotContainsString('Mes commandes', $menu);

        $this->open('/compte', 'fr');
        self::assertSame(200, $this->httpStatus());
        // Un fournisseur n'a ni commandes, ni adresses, ni suppression de compte libre-service.
        self::assertCount(0, $this->client->getCrawler()->filter('[data-account-section="orders"]'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-modal="delete-account"]'));
    }

    public function testLanguageSwitchLinksAndStoredChoice(): void
    {
        $this->open('/', 'fr');
        $links = $this->client->getCrawler()->filter('#lang-menu a')->each(static fn ($a): string => (string) $a->attr('href'));
        self::assertSame(['/langue/fr', '/langue/en', '/langue/es'], $links);

        // Le choix explicite l'emporte sur la langue du navigateur.
        $this->client->request('GET', '/langue/es', server: ['HTTP_REFERER' => 'http://localhost/']);
        $this->open('/', 'fr');
        self::assertSame('es', $this->client->getCrawler()->filter('html')->attr('lang'));
    }

    public function testJsDictionaryIsEmbeddedInTheRequestLanguage(): void
    {
        foreach (['fr' => 'Article ajouté au panier', 'en' => 'Item added to cart', 'es' => 'Artículo añadido a la cesta'] as $locale => $text) {
            $this->open('/', $locale);
            $json = $this->client->getCrawler()->filter('script#gb-i18n')->text(null, false);
            $dictionary = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame($text, $dictionary['cart.added'], $locale);
        }
    }

    public function testDeliveryFeeIsExposedToTheCart(): void
    {
        $this->open('/', 'fr');
        self::assertSame('10000', $this->client->getCrawler()->filter('body')->attr('data-delivery-fee'));
    }

    // ------------------------------------------------------------------ Page de connexion

    public function testSignedInPeopleAreRedirectedAwayFromTheLoginPage(): void
    {
        $this->signIn($this->createUser());
        $this->client->request('GET', '/connexion');
        self::assertSame(302, $this->httpStatus());
        self::assertSame('/', $this->client->getResponse()->headers->get('Location'));
    }

    public function testLoginPageRemembersASafeNextPathOnly(): void
    {
        $user = $this->createUser();

        // Chemin sûr de ce site : la personne y arrive juste après sa connexion.
        $this->client->request('GET', '/connexion?next=/compte');
        $r = $this->api('POST', '/auth/login', ['email' => $user->getEmail(), 'password' => self::PASSWORD]);
        self::assertSame('done', $r['step']);
        self::assertSame('/compte', $r['redirect']);

        // Adresse d'un autre site (« open redirect ») : ignorée, retour à l'accueil.
        $this->newBrowser();
        $this->client->request('GET', '/connexion?next=//site-pirate.example/faux');
        $r = $this->api('POST', '/auth/login', ['email' => $user->getEmail(), 'password' => self::PASSWORD]);
        self::assertSame('done', $r['step']);
        self::assertSame('/', $r['redirect']);
    }

    public function testUnknownModeFallsBackToLogin(): void
    {
        $crawler = $this->client->request('GET', '/connexion?mode=%3Cscript%3Ealert(1)%3C/script%3E');
        self::assertSame(200, $this->httpStatus());
        self::assertSame('login', $crawler->filter('[data-auth]')->attr('data-auth-initial'));
    }

    public function testFailedSocialSignInShowsATranslatedMessage(): void
    {
        // La personne clique « Annuler » chez Google : le retour affiche un message clair (pas un code technique), dans la langue de la page.
        $this->client->request('GET', '/auth/social/google/callback?error=access_denied', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertSame(302, $this->httpStatus());
        $crawler = $this->client->request('GET', (string) $this->client->getResponse()->headers->get('Location'), server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertStringContainsString('Sign-in cancelled', (string) $crawler->filter('[data-auth]')->attr('data-auth-flash-error'));
        self::assertStringNotContainsString('social.error', (string) $this->client->getResponse()->getContent());
    }
}
