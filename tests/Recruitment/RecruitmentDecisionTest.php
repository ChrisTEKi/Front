<?php

namespace App\Tests\Recruitment;

use App\Entity\ApplicationDocument;
use App\Entity\Interview;
use App\Entity\JobApplication;
use App\Enum\ApplicationStatus;
use App\Enum\InterviewStatus;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\ModerationHelpers;
use App\Tests\Support\RecruitmentFixtures;

/**
 * Décisions de l'équipe sur un candidat : lecture des pièces (tracée), refus avec e-mail au nom du candidat, repère « retenu », effacement.
 */
final class RecruitmentDecisionTest extends AppTestCase
{
    use ModerationHelpers;
    use RecruitmentFixtures;

    private function stored(JobApplication $application): ?JobApplication
    {
        $this->em()->clear();

        return $this->em()->find(JobApplication::class, $application->getId());
    }

    private function decider(): void
    {
        $this->signInAdmin($this->adminWith(['recruitment.view', 'recruitment.decide']));
    }

    private function decision(JobApplication $application, string $action, array $extra = []): array
    {
        return $this->api('POST', '/admin/api/recrutement/candidats/'.$this->appId($application).'/statut', ['action' => $action] + $extra);
    }

    // ------------------------------------------------------------------ Lecture des pièces

    public function testAdminsReadTheCvAndTheLetterAsDownloadsAndEveryReadIsAudited(): void
    {
        $application = $this->candidate($this->offer(), 'Marie Rakoto', 'marie@example.com', 'now', 'fr', true);
        $this->signInAdmin($this->adminWith(['recruitment.view']));

        $this->client->request('GET', '/admin/recrutement/candidats/'.$this->appId($application));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-document-kind="cv"] [data-document-download]');
        self::assertSelectorExists('[data-document-kind="cover_letter"] [data-document-download]');

        $document = $this->em()->getRepository(ApplicationDocument::class)->findOneBy(['fileName' => 'mon-cv.pdf']);
        $this->client->request('GET', '/admin/recrutement/pieces/'.$document->getId()->toRfc4122());

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::PDF, $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('cv-marie-rakoto.pdf', (string) $response->headers->get('Content-Disposition'), 'Le nom du fichier est reconstruit, pas repris tel quel.');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertContains('admin.job_application.document_viewed', $this->actions('admin.job_application'));
    }

    public function testWithoutTheViewRightNothingCanBeRead(): void
    {
        $application = $this->candidate($this->offer());
        $document = $this->em()->getRepository(ApplicationDocument::class)->findOneBy([]);
        $this->signInAdmin($this->adminWith([]));

        $this->client->request('GET', '/admin/recrutement/pieces/'.$document->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/recrutement/candidats/'.$this->appId($application));
        self::assertResponseStatusCodeSame(403);
        self::assertNotContains('admin.job_application.document_viewed', $this->actions('admin.job_application'));
    }

    public function testAnonymousVisitorsCannotReachTheDocuments(): void
    {
        $this->candidate($this->offer());
        $document = $this->em()->getRepository(ApplicationDocument::class)->findOneBy([]);

        $this->client->request('GET', '/admin/recrutement/pieces/'.$document->getId()->toRfc4122());

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('%PDF', (string) $this->client->getResponse()->getContent());
    }

    public function testAnUnknownDocumentIsNotFound(): void
    {
        $this->signInAdmin($this->adminWith(['recruitment.view']));

        $this->client->request('GET', '/admin/recrutement/pieces/01a1b4d5-b0b9-77a0-8412-b41f63c8cbce');

        self::assertResponseStatusCodeSame(404);
    }

    // ------------------------------------------------------------------ Examen

    public function testOpeningTheFileMovesANewCandidateToReviewOnlyForSomeoneWhoCanDecide(): void
    {
        $offer = $this->offer();
        $one = $this->candidate($offer, 'Premier Candidat', 'un@example.com');
        $two = $this->candidate($offer, 'Second Candidat', 'deux@example.com');

        $this->signInAdmin($this->adminWith(['recruitment.view'], 'lecteur@example.com'));
        $this->client->request('GET', '/admin/recrutement/candidats/'.$this->appId($one));
        self::assertSame(ApplicationStatus::New, $this->stored($one)->getStatus(), 'Un simple lecteur ne change rien.');

        $this->newBrowser();
        $this->signInAdmin($this->adminWith(['recruitment.view', 'recruitment.decide'], 'decideur@example.com'));
        $this->client->request('GET', '/admin/recrutement/candidats/'.$this->appId($two));
        self::assertSame(ApplicationStatus::Reviewing, $this->stored($two)->getStatus());
        self::assertContains('admin.job_application.reviewing', $this->actions('admin.job_application'));
    }

    // ------------------------------------------------------------------ Refus

    public function testRefusalSendsAnEmailAddressedToTheCandidateByName(): void
    {
        $application = $this->candidate($this->offer('Responsable logistique'), 'Marie Rakoto', 'marie@example.com');
        $this->decider();

        $r = $this->decision($application, 'refuse', ['note' => 'Nous cherchons un profil plus senior.']);

        self::assertSame(200, $this->httpStatus());
        self::assertArrayNotHasKey('warning', $r);
        $stored = $this->stored($application);
        self::assertSame(ApplicationStatus::Refused, $stored->getStatus());
        self::assertSame('Nous cherchons un profil plus senior.', $stored->getRefusalNote());

        self::assertEmailCount(1);
        $mail = self::getMailerMessage(0);
        self::assertEmailHeaderSame($mail, 'To', 'Marie Rakoto <marie@example.com>');
        self::assertStringContainsString('Marie Rakoto', (string) $mail->getSubject());
        self::assertStringContainsString('Responsable logistique', (string) $mail->getSubject());
        self::assertEmailTextBodyContains($mail, 'Bonjour Marie Rakoto');
        self::assertEmailTextBodyContains($mail, 'candidature de Marie Rakoto');
        self::assertEmailTextBodyContains($mail, 'Nous cherchons un profil plus senior.');
        self::assertEmailHtmlBodyContains($mail, 'Marie Rakoto');
        self::assertContains('admin.job_application.refused', $this->actions('admin.job_application'));
    }

    public function testRefusalIsWrittenInTheCandidatesOwnLanguage(): void
    {
        $application = $this->candidate($this->offer('Logistics lead'), 'John Smith', 'john@example.com', 'now', 'en');
        $this->decider();

        $this->decision($application, 'refuse');

        $mail = self::getMailerMessage(0);
        self::assertEmailTextBodyContains($mail, 'Hello John Smith');
        self::assertEmailTextBodyContains($mail, 'John Smith');
    }

    public function testRefusingCancelsTheInterviewsThatWereStillPlannedAndCannotBeDoneTwice(): void
    {
        $application = $this->candidate($this->offer());
        $this->decider();
        $this->api('POST', '/admin/api/recrutement/candidats/'.$this->appId($application).'/entretiens', ['mode' => 'phone', 'at' => $this->slot(3), 'duration' => '30']);
        self::assertSame(201, $this->httpStatus());

        $this->decision($application, 'refuse');
        self::assertSame(200, $this->httpStatus());

        $this->em()->clear();
        foreach ($this->em()->getRepository(Interview::class)->findAll() as $interview) {
            self::assertSame(InterviewStatus::Cancelled, $interview->getStatus());
        }

        $r = $this->decision($application, 'refuse');
        self::assertSame(409, $this->httpStatus());
        self::assertSame('job_already_refused', $r['error']);
    }

    public function testTheRefusalNoteIsLimitedAndAWrongOutcomeSendsNothing(): void
    {
        $application = $this->candidate($this->offer());
        $this->decider();

        $r = $this->decision($application, 'refuse', ['note' => str_repeat('x', 501)]);

        self::assertSame('job_note_too_long', $r['error']);
        self::assertSame(ApplicationStatus::New, $this->stored($application)->getStatus());
        self::assertEmailCount(0);

        $r = $this->decision($application, 'explode');
        self::assertSame(404, $this->httpStatus());
    }

    public function testAReaderCannotRefuse(): void
    {
        $application = $this->candidate($this->offer());
        $this->signInAdmin($this->adminWith(['recruitment.view']));

        $this->decision($application, 'refuse');

        self::assertSame(403, $this->httpStatus());
        self::assertSame(ApplicationStatus::New, $this->stored($application)->getStatus());
        self::assertEmailCount(0);
    }

    // ------------------------------------------------------------------ Retenu

    public function testSelectingACandidateIsAnInternalMarkerWithoutAnyEmail(): void
    {
        $application = $this->candidate($this->offer());
        $this->decider();

        $this->decision($application, 'hire');

        self::assertSame(200, $this->httpStatus());
        self::assertSame(ApplicationStatus::Hired, $this->stored($application)->getStatus());
        self::assertEmailCount(0);

        $r = $this->decision($application, 'refuse');
        self::assertSame(409, $this->httpStatus(), 'Une décision définitive ne se défait pas.');
        self::assertSame('job_application_final', $r['error']);
        $r = $this->api('POST', '/admin/api/recrutement/candidats/'.$this->appId($application).'/entretiens', ['mode' => 'phone', 'at' => $this->slot(3)]);
        self::assertSame('job_application_final', $r['error']);
    }

    // ------------------------------------------------------------------ Effacement

    public function testErasingACandidateNeedsThePasswordAndRemovesEverything(): void
    {
        $offer = $this->offer();
        $application = $this->candidate($offer, 'Marie Rakoto', 'marie@example.com', 'now', 'fr', true);
        $this->decider();
        $this->api('POST', '/admin/api/recrutement/candidats/'.$this->appId($application).'/entretiens', ['mode' => 'phone', 'at' => $this->slot(3)]);

        $this->api('POST', '/admin/api/recrutement/candidats/'.$this->appId($application).'/supprimer', ['password' => 'mauvais']);
        self::assertSame(403, $this->httpStatus());
        self::assertNotNull($this->stored($application));

        $r = $this->api('POST', '/admin/api/recrutement/candidats/'.$this->appId($application).'/supprimer', ['password' => self::PASSWORD]);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('/admin/recrutement/offres/'.$this->offerId($offer), $r['redirect']);
        self::assertNull($this->stored($application));
        self::assertSame(0, $this->em()->getRepository(ApplicationDocument::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Interview::class)->count([]));
        self::assertContains('admin.job_application.deleted', $this->actions('admin.job_application'));
    }

    public function testTheCandidateListFiltersByStatusAndSortsByApplicationDate(): void
    {
        $offer = $this->offer();
        $old = $this->candidate($offer, 'Ancien Candidat', 'ancien@example.com', '-10 days');
        $new = $this->candidate($offer, 'Récent Candidat', 'recent@example.com', '-1 day');
        $refused = $this->candidate($offer, 'Refusé Candidat', 'refuse@example.com', '-5 days');
        $this->signInAdmin($this->adminWith(['recruitment.view', 'recruitment.decide']));
        $this->decision($refused, 'refuse');

        $crawler = $this->client->request('GET', '/admin/recrutement/offres/'.$this->offerId($offer).'?order=asc');
        $ids = $crawler->filter('[data-candidate-row]')->each(static fn ($n): string => (string) $n->attr('data-candidate-row'));
        self::assertSame([$this->appId($old), $this->appId($refused), $this->appId($new)], $ids);

        $crawler = $this->client->request('GET', '/admin/recrutement/offres/'.$this->offerId($offer).'?order=desc');
        self::assertSame([$this->appId($new), $this->appId($refused), $this->appId($old)], $crawler->filter('[data-candidate-row]')->each(static fn ($n): string => (string) $n->attr('data-candidate-row')));

        $crawler = $this->client->request('GET', '/admin/recrutement/offres/'.$this->offerId($offer).'?status=refused');
        self::assertSame([$this->appId($refused)], $crawler->filter('[data-candidate-row]')->each(static fn ($n): string => (string) $n->attr('data-candidate-row')));
    }
}
