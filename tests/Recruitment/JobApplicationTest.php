<?php

namespace App\Tests\Recruitment;

use App\Entity\ApplicationDocument;
use App\Entity\AuditLog;
use App\Entity\JobApplication;
use App\Enum\ApplicationStatus;
use App\Enum\DocumentKind;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\ModerationHelpers;
use App\Tests\Support\RecruitmentFixtures;

/**
 * Dépôt d'une candidature par un visiteur (sans compte) : formulaire, pièces jointes, doublons, limites, pièges à robots.
 */
final class JobApplicationTest extends AppTestCase
{
    use ModerationHelpers;
    use RecruitmentFixtures;

    private function applications(): int
    {
        $this->em()->clear();

        return $this->em()->getRepository(JobApplication::class)->count([]);
    }

    public function testAVisitorAppliesWithACvAndALetterAndGetsAConfirmation(): void
    {
        $offer = $this->offer();

        $r = $this->applyTo($offer, [], ['cv' => $this->upload(self::PDF, 'Mon CV (final).pdf'), 'letter' => $this->upload(self::PDF, 'lettre.pdf')]);

        self::assertSame(201, $this->httpStatus());
        self::assertTrue($r['ok']);
        self::assertArrayNotHasKey('id', $r, 'Le visiteur ne reçoit aucun identifiant de candidature.');

        $application = $this->applicationByEmail('marie@example.com');
        self::assertSame('Marie Rakoto', $application->getFullName());
        self::assertSame('+261341234567', $application->getPhone(), 'Le numéro est enregistré au format international.');
        self::assertSame(ApplicationStatus::New, $application->getStatus());
        self::assertCount(2, $application->getDocuments());
        $cv = $application->getDocument(DocumentKind::Cv);
        self::assertSame('application/pdf', $cv->getContentType());
        self::assertSame(self::PDF, $cv->getContent());
        self::assertSame(strlen(self::PDF), $cv->getSize());

        // Un e-mail de confirmation au candidat et un avis à l'équipe.
        self::assertEmailCount(2);
        $mails = self::getMailerMessages();
        $to = array_map(static fn ($m): string => $m->getTo()[0]->getAddress(), $mails);
        self::assertContains('marie@example.com', $to);
        self::assertContains('support@gobuy.test', $to);
    }

    public function testTheConfirmationIsWrittenInTheLanguageOfTheVisitor(): void
    {
        $offer = $this->offer();
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('gobuy_locale', 'en'));

        $this->applyTo($offer);

        $candidateMail = null;
        foreach (self::getMailerMessages() as $m) {
            if ('marie@example.com' === $m->getTo()[0]->getAddress()) {
                $candidateMail = $m;
            }
        }
        self::assertNotNull($candidateMail);
        self::assertEmailTextBodyContains($candidateMail, 'We have received your application');
        self::assertSame('en', $this->applicationByEmail('marie@example.com')->getLocale());
    }

    public function testTheCvIsRequiredButTheLetterIsNot(): void
    {
        $offer = $this->offer();

        $r = $this->applyTo($offer, [], []);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('job_cv_required', $r['error']);

        $this->applyTo($offer, [], ['cv' => $this->upload(self::PDF)]);
        self::assertSame(201, $this->httpStatus());
        self::assertCount(1, $this->applicationByEmail('marie@example.com')->getDocuments());
    }

    public function testTheTypeIsReadFromTheContentNotFromTheNameOrTheAnnouncedType(): void
    {
        $offer = $this->offer();

        $r = $this->applyTo($offer, [], ['cv' => $this->upload('<?php echo "pirate"; ?>', 'cv.pdf', 'application/pdf')]);
        self::assertSame('job_file_type', $r['error']);
        $r = $this->applyTo($offer, [], ['cv' => $this->upload('<html><script>alert(1)</script></html>', 'cv.png', 'image/png')]);
        self::assertSame('job_file_type', $r['error']);
        $r = $this->applyTo($offer, [], ['cv' => $this->upload('', 'vide.pdf')]);
        self::assertContains($r['error'], ['job_file_invalid', 'job_cv_required']);
        self::assertSame(0, $this->applications());
    }

    public function testFilesAreLimitedInSizeAndInTotal(): void
    {
        $offer = $this->offer();

        $r = $this->applyTo($offer, [], ['cv' => $this->pdfOfSize(2 * 1048576 + 10)]);
        self::assertSame(413, $this->httpStatus());
        self::assertSame('job_file_too_large', $r['error']);

        $r = $this->applyTo($offer, [], ['cv' => $this->pdfOfSize(2 * 1048576), 'letter' => $this->pdfOfSize(2 * 1048576, 'lettre.pdf')]);
        // Deux fichiers de 2 Mo pile : 4 Mo au total, accepté ; au-delà d'un octet : refusé.
        self::assertSame(201, $this->httpStatus(), json_encode($r));
        self::assertSame(1, $this->applications());
    }

    public function testNameEmailAndPhoneAreValidated(): void
    {
        $offer = $this->offer();

        foreach ([
            [['name' => ''], 'job_name_invalid'],
            [['name' => '12345'], 'job_name_invalid'],
            [['email' => 'pas-un-email'], 'job_email_invalid'],
            [['phone' => 'abc'], 'job_phone_invalid'],
            [['message' => str_repeat('a', 2001)], 'job_message_length'],
        ] as [$fields, $error]) {
            $r = $this->applyTo($offer, $fields);
            self::assertSame($error, $r['error'] ?? null, json_encode($fields));
            self::assertSame(422, $this->httpStatus());
        }
        self::assertSame(0, $this->applications());
    }

    public function testOnePersonAppliesOnlyOnceToAGivenOfferEvenWithAnotherCase(): void
    {
        $offer = $this->offer();
        $other = $this->offer('Autre poste');

        $this->applyTo($offer);
        self::assertSame(201, $this->httpStatus());

        $r = $this->applyTo($offer, ['email' => 'MARIE@Example.com']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('job_already_applied', $r['error']);

        $this->applyTo($other);
        self::assertSame(201, $this->httpStatus(), 'La même personne peut postuler à une autre offre.');
        self::assertSame(2, $this->applications());
    }

    public function testAClosedDraftOrExpiredOfferAcceptsNothing(): void
    {
        $draft = $this->offer('Brouillon', false);
        $expired = $this->offer('Expirée', true, '-1 day');

        $this->applyTo($draft);
        self::assertSame(409, $this->httpStatus());
        $r = $this->applyTo($expired);
        self::assertSame('job_offer_closed', $r['error']);
        self::assertSame(0, $this->applications());
    }

    public function testAnUnknownOfferIsNotFound(): void
    {
        $this->client->request('POST', '/emplois/01a1b4d5-b0b9-77a0-8412-b41f63c8cbce/candidater', [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken()]);

        self::assertSame(404, $this->httpStatus());
    }

    public function testTheCsrfTokenIsRequired(): void
    {
        $offer = $this->offer();

        $this->client->request('POST', '/emplois/'.$this->offerId($offer).'/candidater', ['name' => 'Marie Rakoto', 'email' => 'marie@example.com', 'phone' => '0341234567'], ['cv' => $this->upload(self::PDF)], ['HTTP_ACCEPT' => 'application/json']);

        self::assertGreaterThanOrEqual(400, $this->httpStatus());
        self::assertSame(0, $this->applications());
    }

    public function testTheHiddenTrapFieldMakesARobotBelieveItSucceededWithoutSavingAnything(): void
    {
        $offer = $this->offer();

        $r = $this->applyTo($offer, ['website' => 'http://spam.example']);

        self::assertSame(201, $this->httpStatus());
        self::assertTrue($r['ok']);
        self::assertSame(0, $this->applications());
        self::assertEmailCount(0);
    }

    public function testNoMoreThanEightApplicationsPerHourFromOneAddress(): void
    {
        $offer = $this->offer();

        for ($i = 1; $i <= 8; ++$i) {
            $this->applyTo($offer, ['email' => 'candidat'.$i.'@example.com'], null, '198.51.100.99');
            self::assertSame(201, $this->httpStatus(), 'Envoi '.$i);
        }
        $r = $this->applyTo($offer, ['email' => 'candidat9@example.com'], null, '198.51.100.99');
        self::assertSame(429, $this->httpStatus());
        self::assertSame('job_rate_limited', $r['error']);

        $this->applyTo($offer, ['email' => 'candidat10@example.com'], null, '198.51.100.100');
        self::assertSame(201, $this->httpStatus(), 'Une autre adresse n\'est pas bloquée.');
    }

    public function testTheAuditTrailNeverContainsPersonalData(): void
    {
        $offer = $this->offer();
        $this->applyTo($offer);

        $this->em()->clear();
        $logs = array_filter($this->em()->getRepository(AuditLog::class)->findAll(), static fn (AuditLog $l): bool => 'job.application.received' === $l->getAction());
        self::assertCount(1, $logs);
        $dump = json_encode(array_map(static fn (AuditLog $l): array => [$l->getMetadata(), $l->getActorEmail(), $l->getTargetId()], $logs));
        foreach (['Marie', 'Rakoto', 'marie@example.com', '+261341234567', 'motivée'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $dump);
        }
    }

    public function testThePublicPageShowsTheFormWithCvLetterAndTrapField(): void
    {
        $offer = $this->offer();

        $this->client->request('GET', '/emplois/'.$this->offerId($offer));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-job-form][enctype="multipart/form-data"]');
        self::assertSelectorExists('input[name="cv"][type="file"][required]');
        self::assertSelectorExists('input[name="letter"][type="file"]:not([required])');
        self::assertSelectorExists('input[name="website"][tabindex="-1"]');
        self::assertSelectorExists('[data-job-apply][data-max-file-mb="2"][data-max-total-mb="4"]');
        self::assertSelectorExists('a[href="/emplois"]');
    }

    public function testTheFooterLinksToTheJobsPage(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorExists('footer a[href="/emplois"]');
    }

    public function testDocumentsAreStoredAsOneCvAndOneLetterAtMost(): void
    {
        $offer = $this->offer();
        $this->applyTo($offer, [], ['cv' => $this->upload(self::PDF), 'letter' => $this->upload(self::PDF)]);

        $this->em()->clear();
        $kinds = array_map(static fn (ApplicationDocument $d): string => $d->getKind()->value, $this->em()->getRepository(ApplicationDocument::class)->findAll());
        sort($kinds);
        self::assertSame(['cover_letter', 'cv'], $kinds);
    }
}
