<?php

namespace App\Tests\Admin;

use App\Entity\AdminRole;
use App\Entity\AuditLog;
use App\Entity\CommissionRate;
use App\Entity\SupplierProfile;
use App\Enum\AccountType;
use App\Supplier\CommissionSchedule;
use App\Tests\Support\AppTestCase;

/**
 * Taux de commission par rayon, modifiables par l'administration (droit « commissions.manage ») :
 * valeurs d'origine, accès, confirmation par mot de passe, validation faite par le serveur, journal d'audit,
 * et lecture des taux par la page publique « Devenir partenaire » et par l'Espace Fournisseur.
 */
final class AdminCommissionTest extends AppTestCase
{
    /** Taux d'origine, dans l'ordre d'affichage (identiques à ceux de l'ancien barème « standard »). */
    private const ORIGINAL = [
        'cat-alim' => 4, 'cat-elec' => 5, 'cat-enfants' => 6, 'cat-electromenager' => 6,
        'cat-maison' => 7, 'cat-sport' => 7, 'cat-mode' => 8, 'cat-beaute' => 8,
    ];

    // ------------------------------------------------------------------ Aides

    private function schedule(): CommissionSchedule
    {
        return static::getContainer()->get(CommissionSchedule::class);
    }

    /** Un PDG connecté (il a le droit par défaut, posé par la migration). */
    private function signInPdg(string $roleCode = 'pdg'): void
    {
        $this->signInAdmin($this->createAdminUser($roleCode.'@example.com', $roleCode));
    }

    /** @param array<string, mixed> $rates @return array<string, mixed> */
    private function save(array $rates, ?string $password = self::PASSWORD): array
    {
        return $this->api('POST', '/admin/api/commissions', $rates + (null === $password ? [] : ['password' => $password]));
    }

    /** @return array<string, string> rayon => taux stocké (« 7.50 ») */
    private function stored(): array
    {
        $this->em()->clear();
        $rows = [];
        foreach ($this->em()->getRepository(CommissionRate::class)->findAll() as $row) {
            $rows[$row->getCategory()] = $row->getRate();
        }
        ksort($rows);

        return $rows;
    }

    // ------------------------------------------------------------------ Valeurs d'origine

    public function testOriginalRatesApplyWhenNothingIsStored(): void
    {
        $rows = $this->schedule()->all();

        self::assertSame(array_keys(self::ORIGINAL), array_column($rows, 'id'));
        self::assertSame(array_values(self::ORIGINAL), array_column($rows, 'rate'));
        foreach ($rows as $row) {
            self::assertNull($row['updatedAt']);
            self::assertNull($row['updatedBy']);
        }
        self::assertSame(8, $this->schedule()->rateFor('cat-mode'));
        self::assertNull($this->schedule()->rateFor('cat-armes'));
        self::assertNull($this->schedule()->rateFor(null));
    }

    public function testOnlyPdgAndDgProfilesGetTheRightByDefault(): void
    {
        foreach (['pdg' => true, 'dg' => true, 'finance' => false, 'compta' => false, 'moderation' => false, 'support' => false] as $code => $granted) {
            $role = $this->em()->getRepository(AdminRole::class)->findOneBy(['code' => $code]);
            self::assertNotNull($role, $code);
            self::assertSame($granted, \in_array('commissions.manage', $role->getPermissions(), true), $code);
        }
    }

    // ------------------------------------------------------------------ Accès à la page

    public function testPageShowsEveryRateToAnAuthorisedAdmin(): void
    {
        $this->signInPdg();
        $this->client->request('GET', '/admin/commissions');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $crawler = $this->client->getCrawler();
        self::assertCount(8, $crawler->filter('[data-commission-row]'));
        foreach (self::ORIGINAL as $id => $rate) {
            self::assertSame((string) $rate, $crawler->filter('input[name="'.$id.'"]')->attr('value'), $id);
        }
        self::assertCount(1, $crawler->filter('form[data-admin-form] input[type="password"][name="password"]'));
        self::assertSame('/admin/api/commissions', $crawler->filter('form[data-admin-form]')->attr('data-endpoint'));
    }

    public function testSuperAdminCanOpenThePage(): void
    {
        $this->signInAdmin($this->createSuperAdmin());
        $this->client->request('GET', '/admin/commissions');

        self::assertResponseIsSuccessful();
    }

    public function testAdminsWithoutTheRightAreRefusedOnPageAndOnSave(): void
    {
        foreach (['finance', 'compta', 'moderation', 'support'] as $code) {
            $this->signInPdg($code);
            $this->client->request('GET', '/admin/commissions');
            self::assertResponseStatusCodeSame(403, $code);

            $this->save(['cat-mode' => '1']);
            self::assertSame(403, $this->httpStatus(), $code);
            self::assertSame([], $this->stored(), $code.' : rien ne doit être enregistré.');
            $this->newBrowser();
        }
    }

    public function testVisitorsAndClientsCannotReachIt(): void
    {
        $this->client->request('GET', '/admin/commissions');
        self::assertNotSame(200, $this->httpStatus());

        $this->signIn($this->createUser('client@example.com'));
        $this->client->request('GET', '/admin/commissions');
        self::assertContains($this->httpStatus(), [302, 401, 403, 404]);
        $this->save(['cat-mode' => '1']);
        self::assertContains($this->httpStatus(), [302, 401, 403, 404]);
        self::assertSame([], $this->stored());
    }

    public function testDashboardLinksToThePageOnlyForThoseWhoCanUseIt(): void
    {
        $this->signInPdg();
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('href="/admin/commissions"', (string) $this->client->getResponse()->getContent());

        $this->newBrowser();
        $this->signInPdg('compta');
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('href="/admin/commissions"', (string) $this->client->getResponse()->getContent());
    }

    // ------------------------------------------------------------------ Enregistrement

    public function testSavingNeedsThePasswordAgain(): void
    {
        $this->signInPdg();

        foreach ([null, '', 'mauvais-mot-de-passe'] as $wrong) {
            $r = $this->save(['cat-mode' => '9'], $wrong);
            self::assertSame(403, $this->httpStatus());
            self::assertSame('step_up_failed', $r['error']);
        }
        self::assertSame([], $this->stored());
        self::assertSame(8, $this->schedule()->rateFor('cat-mode'));
    }

    public function testSavingStoresOnlyWhatChangedAndKeepsAnAuditTrail(): void
    {
        $this->signInPdg();

        // La page renvoie tous les rayons : seuls ceux dont la valeur change sont écrits.
        $body = array_map('strval', self::ORIGINAL);
        $body['cat-mode'] = '9,5';
        $body['cat-alim'] = '3';
        $r = $this->save($body);

        self::assertSame(200, $this->httpStatus(), json_encode($r));
        self::assertTrue($r['ok']);
        self::assertSame(2, $r['changed']);
        self::assertSame(['cat-alim' => '3.00', 'cat-mode' => '9.50'], $this->stored());
        self::assertSame(9.5, $this->schedule()->rateFor('cat-mode'));
        self::assertSame(3, $this->schedule()->rateFor('cat-alim'));
        self::assertSame(5, $this->schedule()->rateFor('cat-elec'), 'Un rayon non modifié garde son taux.');

        $logs = $this->em()->getRepository(AuditLog::class)->findBy(['action' => 'admin.commission.changed']);
        self::assertCount(1, $logs);
        self::assertSame('pdg@example.com', $logs[0]->getActorEmail());
        $changes = array_map(static function (array $c): array { ksort($c); return $c; }, $logs[0]->getMetadata()['changes']);
        usort($changes, static fn ($a, $b) => $a['category'] <=> $b['category']);
        self::assertSame([
            ['category' => 'cat-alim', 'from' => '4.00', 'to' => '3.00'],
            ['category' => 'cat-mode', 'from' => '8.00', 'to' => '9.50'],
        ], $changes);
        self::assertStringNotContainsString(self::PASSWORD, json_encode($logs[0]->getMetadata()));

        $row = $this->em()->getRepository(CommissionRate::class)->find('cat-mode');
        self::assertSame('pdg@example.com', $row->getUpdatedBy()?->getEmail());
    }

    public function testASecondSaveUpdatesTheSameRowAndHistoryListsBoth(): void
    {
        $this->signInPdg();
        $this->save(['cat-sport' => '6']);
        $this->save(['cat-sport' => '6.25']);

        self::assertSame(['cat-sport' => '6.25'], $this->stored());
        self::assertSame(2, $this->em()->getRepository(AuditLog::class)->count(['action' => 'admin.commission.changed']));

        $this->client->request('GET', '/admin/commissions');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame('6.25', $this->client->getCrawler()->filter('input[name="cat-sport"]')->attr('value'));
        self::assertStringContainsString('6.25', $html);
        self::assertStringContainsString('pdg@example.com', $html, 'L\'historique dit qui a modifié.');
        // La ligne modifiée affiche qui l'a changée et quand, plus la mention « taux d'origine ».
        self::assertStringContainsString('Admin pdg', $this->client->getCrawler()->filter('[data-commission-row="cat-sport"]')->text());
        self::assertStringContainsString('Taux d\'origine', $this->client->getCrawler()->filter('[data-commission-row="cat-mode"]')->text());
    }

    public function testCommaDecimalsTrailingZerosAndNumbersAreAccepted(): void
    {
        $this->signInPdg();

        $this->save(['cat-mode' => '7,5', 'cat-elec' => ' 5.10 ', 'cat-maison' => 0, 'cat-sport' => 50]);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(['cat-elec' => '5.10', 'cat-maison' => '0.00', 'cat-mode' => '7.50', 'cat-sport' => '50.00'], $this->stored());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidRates(): array
    {
        return [
            'above the cap' => ['50.01'], 'way above' => ['80'], 'three digits' => ['100'], 'negative' => ['-1'], 'text' => ['huit'],
            'three decimals' => ['7.555'], 'empty' => [''], 'null' => [null], 'array' => [['8']], 'bool' => [true],
            'exponent' => ['1e1'], 'only a comma' => [','], 'two separators' => ['7.5.1'], 'percent sign' => ['8 %'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRates')]
    public function testAnInvalidRateRefusesEverythingAndChangesNothing(mixed $bad): void
    {
        $this->signInPdg();

        // Un taux valide envoyé AVEC un taux invalide n'est pas enregistré non plus : pas de changement partiel.
        $r = $this->save(['cat-elec' => '5.5', 'cat-mode' => $bad]);

        self::assertSame(422, $this->httpStatus(), json_encode($r));
        self::assertFalse($r['ok']);
        self::assertSame('invalid_commission_rate', $r['error']);
        self::assertStringContainsString('50', $r['message']);
        self::assertSame([], $this->stored());
        self::assertSame(0, $this->em()->getRepository(AuditLog::class)->count(['action' => 'admin.commission.changed']));
    }

    public function testSavingTheSameValuesIsReportedAndNotLogged(): void
    {
        $this->signInPdg();

        $r = $this->save(array_map('strval', self::ORIGINAL));

        self::assertSame(422, $this->httpStatus());
        self::assertSame('commission_no_change', $r['error']);
        self::assertSame([], $this->stored());
        self::assertSame(0, $this->em()->getRepository(AuditLog::class)->count(['action' => 'admin.commission.changed']));
    }

    public function testUnknownCategoriesAreIgnored(): void
    {
        $this->signInPdg();

        $r = $this->save(['cat-armes' => '1', 'cat-mode' => '8.5', 'isAdmin' => true]);

        self::assertSame(200, $this->httpStatus(), json_encode($r));
        self::assertSame(['cat-mode' => '8.50'], $this->stored());
        self::assertSame(1, $r['changed']);
        // Seulement des rayons inconnus : rien à faire.
        $r = $this->save(['cat-armes' => '1']);
        self::assertSame('commission_no_change', $r['error']);
    }

    public function testRevertingToTheOriginalRateStillWritesARow(): void
    {
        $this->signInPdg();
        $this->save(['cat-mode' => '9']);
        $this->save(['cat-mode' => '8']);

        self::assertSame(['cat-mode' => '8.00'], $this->stored());
        self::assertSame(8, $this->schedule()->rateFor('cat-mode'));
        self::assertSame(2, $this->em()->getRepository(AuditLog::class)->count(['action' => 'admin.commission.changed']));
    }

    public function testSaveEndpointNeedsTheCsrfToken(): void
    {
        $this->signInPdg();

        $this->api('POST', '/admin/api/commissions', ['cat-mode' => '9', 'password' => self::PASSWORD], withCsrf: false);

        self::assertContains($this->httpStatus(), [403, 419, 400]);
        self::assertSame([], $this->stored());
    }

    public function testParseIsStrict(): void
    {
        self::assertSame('7.50', CommissionSchedule::parse('7,5'));
        self::assertSame('0.00', CommissionSchedule::parse('0'));
        self::assertSame('50.00', CommissionSchedule::parse(50.0));
        self::assertSame('12.34', CommissionSchedule::parse("1\u{00A0}2.34"));
        $this->expectException(\App\Admin\AdminActionException::class);
        CommissionSchedule::parse('50.5');
    }

    // ------------------------------------------------------------------ Ce que voient les fournisseurs et les futurs partenaires

    public function testPublicPageAndSupplierDashboardShowTheNewRate(): void
    {
        $this->signInPdg();
        $this->save(['cat-elec' => '6,5']);
        $this->newBrowser();

        // Page publique : le bouton du rayon porte le nouveau taux (le simulateur le lit).
        $crawler = $this->client->request('GET', '/devenir-partenaire');
        $rates = [];
        foreach ($crawler->filter('[data-simulator] [data-sim-category]') as $button) {
            $rates[] = $button->getAttribute('data-rate');
        }
        self::assertSame(['4', '6.5', '6', '6', '7', '7', '8', '8'], $rates);

        // Espace Fournisseur : le taux prévu est celui du rayon principal du dossier.
        $user = $this->createUser('marie@boutique.mg', AccountType::Supplier, true, '+261331112233', true);
        $this->em()->persist(new SupplierProfile($this->reload($user), 'Boutique Marie', 'Lot 12 Analakely', 'Électronique', new \DateTimeImmutable(), ['mainCategory' => 'cat-elec']));
        $this->em()->flush();
        $this->em()->clear();
        $this->newBrowser();
        $this->signIn($user);
        $this->client->request('GET', '/fournisseur');

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/data-commission-rate[^>]*>\s*6[,.]5\s?%/', (string) $this->client->getResponse()->getContent());
    }

    public function testAMissingRowFallsBackToTheOriginalRate(): void
    {
        $this->signInPdg();
        $this->save(['cat-mode' => '9']);
        self::assertSame(9, $this->schedule()->rateFor('cat-mode'));

        // Ligne supprimée à la main : le taux d'origine redevient celui du rayon (jamais d'erreur ni de rayon vide).
        static::getContainer()->get('doctrine.dbal.default_connection')->executeStatement('DELETE FROM commission_rates');
        $this->newBrowser();
        $this->client->request('GET', '/devenir-partenaire');

        self::assertResponseIsSuccessful();
        self::assertSame('8', $this->client->getCrawler()->filter('[data-sim-category][data-rate="8"]')->first()->attr('data-rate'));
    }
}
