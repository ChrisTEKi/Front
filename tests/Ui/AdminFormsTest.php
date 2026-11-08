<?php

namespace App\Tests\Ui;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\ModerationHelpers;
use App\Tests\Support\SupplierFixtures;

/**
 * Garde-fous des formulaires de l'espace administrateur (bannir, supprimer, geler, rembourser, liste noire, taux de change…).
 *
 * Contexte : ces formulaires avaient reçu le même attribut `data-account-form` que ceux du compte CLIENT. Le script du compte
 * client (assets/account.js) s'y accrochait donc aussi et VIDAIT le champ « mot de passe » juste avant l'envoi : la validation
 * partait sans mot de passe et échouait. Les tests PHP ne voient pas le navigateur ; ces contrôles verrouillent la règle
 * qui évite le retour du défaut.
 */
final class AdminFormsTest extends AppTestCase
{
    use ModerationHelpers;
    use SupplierFixtures;

    private const ROOT = __DIR__.'/../..';

    /** @return list<string> */
    private function adminTemplates(): array
    {
        return glob(self::ROOT.'/templates/admin/*.twig') ?: [];
    }

    public function testAdminTemplatesNeverUseTheCustomerAccountFormAttribute(): void
    {
        $templates = $this->adminTemplates();
        self::assertNotEmpty($templates);
        foreach ($templates as $file) {
            self::assertStringNotContainsString('data-account-form', (string) file_get_contents($file), basename($file).' : « data-account-form » est réservé au compte client (assets/account.js).');
        }
    }

    public function testEveryAdminFormIsPostedAndHasItsEndpoint(): void
    {
        $count = 0;
        foreach ($this->adminTemplates() as $file) {
            preg_match_all('/<form\b[^>]*\bdata-admin-form\b[^>]*>/', (string) file_get_contents($file), $forms);
            foreach ($forms[0] as $tag) {
                ++$count;
                self::assertStringContainsString('method="post"', $tag, basename($file).' : sans method="post", un échec du script enverrait le mot de passe dans l\'adresse de la page.');
                self::assertStringContainsString('data-endpoint=', $tag, basename($file));
            }
        }
        self::assertGreaterThanOrEqual(10, $count, 'Les formulaires admin n\'ont pas été trouvés.');
    }

    public function testTheCustomerAccountScriptOnlyBindsNamedForms(): void
    {
        $js = (string) file_get_contents(self::ROOT.'/assets/account.js');

        self::assertStringNotContainsString("querySelectorAll('[data-account-form]')", $js, 'Il faut exclure les formulaires sans nom.');
        self::assertStringContainsString('[data-account-form]:not([data-account-form=""])', $js);

        $admin = (string) file_get_contents(self::ROOT.'/assets/admin-account.js');
        self::assertStringContainsString("querySelectorAll('[data-admin-form]')", $admin);
        self::assertStringNotContainsString("querySelectorAll('[data-account-form]')", $admin);
    }

    public function testTheRenderedAccountPageUsesTheAdminAttributeAndNoWelcomeWindow(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $this->signInAdmin($this->createSuperAdmin());

        $this->client->request('GET', '/admin/comptes/'.$this->id($supplier));
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertGreaterThanOrEqual(4, substr_count($html, 'data-admin-form'), 'Gel, remboursement, suppression, bannissement.');
        self::assertStringNotContainsString('data-account-form', $html);
        self::assertStringNotContainsString('data-onboarding', $html, 'La fenêtre de bienvenue masquerait la page et bloquerait les clics.');
    }

    public function testTheWelcomeWindowStillExistsOnTheShop(): void
    {
        $this->client->request('GET', '/');

        self::assertStringContainsString('data-onboarding', (string) $this->client->getResponse()->getContent());
    }

    public function testEveryAdminFormGetsMarkedAsBoundOnlyWhenTheScriptRuns(): void
    {
        $admin = (string) file_get_contents(self::ROOT.'/assets/admin-account.js');
        self::assertStringContainsString("setAttribute('data-gb-bound'", $admin, 'Sans ce marqueur, la garde de la page ne sait pas que le script est chargé.');

        $app = (string) file_get_contents(self::ROOT.'/assets/app.js');
        self::assertStringContainsString('function safely(', $app, 'Un module qui plante ne doit pas empêcher les suivants de démarrer.');
        self::assertStringContainsString('.forEach(safely)', $app);
        self::assertStringContainsString('window.__gbReady = true', $app);
    }

    public function testTheAdminPagesCarryTheGuardAgainstMissingScripts(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg');
        $this->signInAdmin($this->createSuperAdmin());

        $this->client->request('GET', '/admin/comptes/'.$this->id($supplier));
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-gb-bound', $html, 'La garde bloque tout envoi natif d\'un formulaire admin dont le script n\'est pas chargé.');
        self::assertStringContainsString('data-scripts-warning', $html);
        // Le message est inséré par json_encode : les accents y sont écrits \uXXXX (valide pour le navigateur).
        self::assertStringContainsString('PAS \u00e9t\u00e9 envoy\u00e9', $html);
    }

    public function testThePublicPagesDoNotCarryTheAdminGuard(): void
    {
        $this->client->request('GET', '/');

        self::assertStringNotContainsString('data-scripts-warning', (string) $this->client->getResponse()->getContent());
    }
}
