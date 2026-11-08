<?php

namespace App\Tests\Recruitment;

use App\Entity\AdminRole;
use App\Entity\JobOffer;
use App\Enum\JobOfferStatus;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\ModerationHelpers;
use App\Tests\Support\RecruitmentFixtures;

/**
 * Offres d'emploi : droits, création / modification / publication / clôture / suppression, visibilité publique.
 */
final class JobOfferTest extends AppTestCase
{
    use ModerationHelpers;
    use RecruitmentFixtures;

    private const FIELDS = ['title' => 'Chargé de support client', 'contract' => 'fixed_term', 'department' => 'Support', 'location' => 'Antananarivo', 'description' => 'Répondre aux clients par e-mail et par téléphone, du lundi au vendredi.', 'profile' => 'Bon relationnel.', 'closingDate' => ''];

    private function stored(string $id): ?JobOffer
    {
        $this->em()->clear();

        return $this->em()->find(JobOffer::class, $id);
    }

    // ------------------------------------------------------------------ Droits

    public function testPagesAndActionsNeedTheRecruitmentRights(): void
    {
        $offer = $this->offer();
        $this->signInAdmin($this->adminWith([]));

        foreach (['/admin/recrutement', '/admin/recrutement/offres/'.$this->offerId($offer), '/admin/recrutement/planning'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->api('POST', '/admin/api/recrutement/offres', self::FIELDS);
        self::assertSame(403, $this->httpStatus());
        self::assertCount(1, $this->em()->getRepository(JobOffer::class)->findAll());
    }

    public function testAReaderSeesEverythingButChangesNothing(): void
    {
        $offer = $this->offer();
        $this->signInAdmin($this->adminWith(['recruitment.view']));

        $this->client->request('GET', '/admin/recrutement');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[data-endpoint$="/recrutement/offres"]', 'Sans le droit de gestion, le formulaire de création est absent.');
        $this->client->request('GET', '/admin/recrutement/offres/'.$this->offerId($offer));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-account-post]', 'Ni publier, ni clôturer.');

        $this->api('POST', '/admin/api/recrutement/offres', self::FIELDS);
        self::assertSame(403, $this->httpStatus());
        $this->api('POST', '/admin/api/recrutement/offres/'.$this->offerId($offer).'/statut', ['action' => 'close']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame(JobOfferStatus::Open, $this->stored($this->offerId($offer))->getStatus());
    }

    public function testTheToolLinkOnlyAppearsWithTheViewRight(): void
    {
        $this->signInAdmin($this->adminWith(['recruitment.view']));
        $this->client->request('GET', '/admin');
        self::assertSelectorExists('a[href="/admin/recrutement"]');
    }

    public function testTheCsrfTokenIsRequired(): void
    {
        $this->signInAdmin($this->adminWith(['recruitment.manage']));

        $this->api('POST', '/admin/api/recrutement/offres', self::FIELDS, false);

        self::assertGreaterThanOrEqual(400, $this->httpStatus());
        self::assertCount(0, $this->em()->getRepository(JobOffer::class)->findAll());
    }

    // ------------------------------------------------------------------ Cycle de vie

    public function testCreateEditPublishAndCloseAnOffer(): void
    {
        $this->signInAdmin($this->adminWith(['recruitment.view', 'recruitment.manage']));

        $r = $this->api('POST', '/admin/api/recrutement/offres', self::FIELDS);
        self::assertSame(201, $this->httpStatus());
        self::assertStringStartsWith('/admin/recrutement/offres/', $r['redirect']);
        $id = substr($r['redirect'], \strlen('/admin/recrutement/offres/'));
        self::assertSame(JobOfferStatus::Draft, $this->stored($id)->getStatus(), 'Une offre créée est un brouillon invisible.');

        $this->client->request('GET', '/emplois/'.$id);
        self::assertResponseStatusCodeSame(404);

        $this->api('POST', '/admin/api/recrutement/offres/'.$id, ['title' => 'Chargé de support', 'contract' => 'permanent', 'description' => 'Répondre aux clients par e-mail et par téléphone.'] + self::FIELDS);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('Chargé de support', $this->stored($id)->getTitle());

        $this->api('POST', '/admin/api/recrutement/offres/'.$id.'/statut', ['action' => 'publish']);
        self::assertSame(200, $this->httpStatus());
        $this->client->request('GET', '/emplois');
        self::assertSelectorTextContains('[data-job-offer="'.$id.'"]', 'Chargé de support');
        $this->client->request('GET', '/emplois/'.$id);
        self::assertResponseIsSuccessful();

        $r = $this->api('POST', '/admin/api/recrutement/offres/'.$id.'/statut', ['action' => 'publish']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('job_offer_already_open', $r['error']);

        $this->api('POST', '/admin/api/recrutement/offres/'.$id.'/statut', ['action' => 'close']);
        self::assertSame(200, $this->httpStatus());
        $this->client->request('GET', '/emplois');
        self::assertSelectorNotExists('[data-job-offer="'.$id.'"]');
        $this->client->request('GET', '/emplois/'.$id);
        self::assertResponseStatusCodeSame(404);

        $r = $this->api('POST', '/admin/api/recrutement/offres/'.$id.'/statut', ['action' => 'close']);
        self::assertSame('job_offer_not_open', $r['error']);

        self::assertContains('admin.job_offer.published', $this->actions('admin.job_offer'));
        self::assertContains('admin.job_offer.closed', $this->actions('admin.job_offer'));
    }

    public function testInvalidOffersAreRefusedWithAClearMessage(): void
    {
        $this->signInAdmin($this->adminWith(['recruitment.manage']));

        foreach ([
            ['title' => 'ab', 'error' => 'job_title_invalid'],
            ['contract' => 'esclavage', 'error' => 'job_contract_invalid'],
            ['description' => 'trop court', 'error' => 'job_description_invalid'],
            ['closingDate' => '2020-01-01', 'error' => 'job_date_invalid'],
            ['closingDate' => '2026-02-31', 'error' => 'job_date_invalid'],
        ] as $case) {
            $error = $case['error'];
            unset($case['error']);
            $r = $this->api('POST', '/admin/api/recrutement/offres', $case + self::FIELDS);
            self::assertSame($error, $r['error'] ?? null, json_encode($case));
            self::assertGreaterThanOrEqual(400, $this->httpStatus());
            self::assertNotSame('', $r['message']);
        }
        self::assertCount(0, $this->em()->getRepository(JobOffer::class)->findAll());
    }

    public function testAnOfferPastItsClosingDateDisappearsFromTheSiteWithoutBeingClosed(): void
    {
        $open = $this->offer('Offre encore ouverte', true, '+10 days');
        $expired = $this->offer('Offre expirée', true, '-1 day');

        $this->client->request('GET', '/emplois');
        self::assertSelectorExists('[data-job-offer="'.$this->offerId($open).'"]');
        self::assertSelectorNotExists('[data-job-offer="'.$this->offerId($expired).'"]');
        $this->client->request('GET', '/emplois/'.$this->offerId($expired));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(JobOfferStatus::Open, $this->stored($this->offerId($expired))->getStatus());
    }

    public function testAnOfferWithApplicationsCannotBeDeletedButOneWithoutCan(): void
    {
        $used = $this->offer('Offre avec candidat');
        $this->candidate($used);
        $empty = $this->offer('Offre vide');
        $this->signInAdmin($this->adminWith(['recruitment.manage']));

        $r = $this->api('POST', '/admin/api/recrutement/offres/'.$this->offerId($used).'/supprimer', ['password' => self::PASSWORD]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('job_offer_has_applications', $r['error']);

        $this->api('POST', '/admin/api/recrutement/offres/'.$this->offerId($empty).'/supprimer', ['password' => 'mauvais']);
        self::assertSame(403, $this->httpStatus());
        self::assertNotNull($this->stored($this->offerId($empty)), 'Un mot de passe faux ne supprime rien.');

        $r = $this->api('POST', '/admin/api/recrutement/offres/'.$this->offerId($empty).'/supprimer', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('/admin/recrutement', $r['redirect']);
        self::assertNull($this->stored($this->offerId($empty)));
    }

    public function testTextsOfOffersAreEscapedOnThePublicPage(): void
    {
        $this->signInAdmin($this->adminWith(['recruitment.manage']));
        $r = $this->api('POST', '/admin/api/recrutement/offres', ['title' => 'Dév <script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)> description assez longue pour passer.'] + self::FIELDS);
        $id = substr($r['redirect'], \strlen('/admin/recrutement/offres/'));
        $this->api('POST', '/admin/api/recrutement/offres/'.$id.'/statut', ['action' => 'publish']);

        $this->client->request('GET', '/emplois/'.$id);
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // ------------------------------------------------------------------ Droits délégués

    public function testThePdgAndDgProfilesReceivedTheRecruitmentRights(): void
    {
        foreach (['pdg', 'dg'] as $code) {
            $role = $this->em()->getRepository(AdminRole::class)->findOneBy(['code' => $code]);
            foreach (['recruitment.view', 'recruitment.manage', 'recruitment.decide'] as $right) {
                self::assertContains($right, $role->getPermissions(), $code.' : '.$right);
            }
        }
    }
}
