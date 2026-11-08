<?php

namespace App\Tests\Recruitment;

use App\Entity\Interview;
use App\Entity\JobApplication;
use App\Enum\ApplicationStatus;
use App\Enum\InterviewMode;
use App\Enum\InterviewStatus;
use App\Recruitment\RecruitmentTime;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\ModerationHelpers;
use App\Tests\Support\RecruitmentFixtures;

/**
 * Entretiens : par téléphone ou sur place (au choix de l'administrateur), e-mails au candidat, heure de Madagascar, chevauchements,
 * et planning de tous les entretiens classé par date de candidature.
 */
final class InterviewTest extends AppTestCase
{
    use ModerationHelpers;
    use RecruitmentFixtures;

    private function decider(): void
    {
        $this->signInAdmin($this->adminWith(['recruitment.view', 'recruitment.decide']));
    }

    /** @param array<string, string> $data */
    private function schedule(JobApplication $application, array $data): array
    {
        return $this->api('POST', '/admin/api/recrutement/candidats/'.$this->appId($application).'/entretiens', $data);
    }

    private function stored(JobApplication $application): JobApplication
    {
        $this->em()->clear();

        return $this->em()->find(JobApplication::class, $application->getId());
    }

    private function firstInterview(): Interview
    {
        $this->em()->clear();

        return $this->em()->getRepository(Interview::class)->findOneBy([]);
    }

    // ------------------------------------------------------------------ Convocation

    public function testAPhoneInterviewIsScheduledAndTheCandidateIsToldTheDayTheTimeAndTheNumber(): void
    {
        $application = $this->candidate($this->offer('Responsable logistique'), 'Marie Rakoto', 'marie@example.com');
        $this->decider();

        $r = $this->schedule($application, ['mode' => 'phone', 'at' => $this->slot(3, 9, 30), 'duration' => '45', 'note' => 'Prévoir votre CV sous la main.']);

        self::assertSame(201, $this->httpStatus(), json_encode($r));
        self::assertArrayNotHasKey('warning', $r);
        $interview = $this->firstInterview();
        self::assertSame(InterviewMode::Phone, $interview->getMode());
        self::assertSame(45, $interview->getDurationMinutes());
        self::assertNull($interview->getLocation());
        self::assertSame(ApplicationStatus::Interview, $this->stored($application)->getStatus());
        // 9 h 30 à Madagascar (UTC+3) = 6 h 30 UTC.
        self::assertSame('06:30', $interview->getScheduledAt()->setTimezone(new \DateTimeZone('UTC'))->format('H:i'));

        self::assertEmailCount(1);
        $mail = self::getMailerMessage(0);
        self::assertEmailHeaderSame($mail, 'To', 'Marie Rakoto <marie@example.com>');
        self::assertEmailTextBodyContains($mail, 'Bonjour Marie Rakoto');
        self::assertEmailTextBodyContains($mail, '09:30');
        self::assertEmailTextBodyContains($mail, 'heure de Madagascar');
        self::assertEmailTextBodyContains($mail, '45 minutes');
        self::assertEmailTextBodyContains($mail, '+261341234567');
        self::assertEmailTextBodyContains($mail, 'Prévoir votre CV sous la main.');
        self::assertStringContainsString('Responsable logistique', (string) $mail->getSubject());
        self::assertContains('admin.job_interview.scheduled', $this->actions('admin.job_interview'));
    }

    public function testAnInPersonInterviewNeedsAPlaceAndTheEmailGivesIt(): void
    {
        $application = $this->candidate($this->offer());
        $this->decider();

        $r = $this->schedule($application, ['mode' => 'in_person', 'at' => $this->slot(4, 14, 0)]);
        self::assertSame('job_interview_place_required', $r['error']);
        self::assertEmailCount(0);

        $this->schedule($application, ['mode' => 'in_person', 'at' => $this->slot(4, 14, 0), 'location' => 'Bureaux GoBuy, Lot II A 12, Antananarivo']);

        self::assertSame(201, $this->httpStatus());
        self::assertSame('Bureaux GoBuy, Lot II A 12, Antananarivo', $this->firstInterview()->getLocation());
        $mail = self::getMailerMessage(0);
        self::assertEmailTextBodyContains($mail, 'Bureaux GoBuy, Lot II A 12, Antananarivo');
        self::assertEmailTextBodyContains($mail, '14:00');
    }

    public function testInvalidSlotsAreRefused(): void
    {
        $application = $this->candidate($this->offer());
        $this->decider();

        foreach ([
            [['mode' => 'visio', 'at' => $this->slot()], 'job_mode_invalid'],
            [['mode' => 'phone', 'at' => 'demain'], 'job_datetime_invalid'],
            [['mode' => 'phone', 'at' => '2026-02-31T10:00'], 'job_datetime_invalid'],
            [['mode' => 'phone', 'at' => $this->slot(-2)], 'job_interview_past'],
            [['mode' => 'phone', 'at' => $this->slot(800)], 'job_interview_too_far'],
            [['mode' => 'phone', 'at' => $this->slot(), 'duration' => '5'], 'job_interview_duration'],
            [['mode' => 'phone', 'at' => $this->slot(), 'duration' => '999'], 'job_interview_duration'],
            [['mode' => 'phone', 'at' => $this->slot(), 'note' => str_repeat('n', 501)], 'job_note_too_long'],
        ] as [$data, $error]) {
            $r = $this->schedule($application, $data);
            self::assertSame($error, $r['error'] ?? null, json_encode($data));
            self::assertGreaterThanOrEqual(400, $this->httpStatus());
        }
        self::assertSame(0, $this->em()->getRepository(Interview::class)->count([]));
        self::assertEmailCount(0);
    }

    public function testTwoInterviewsOfTheSameCandidateCannotOverlapButDifferentCandidatesCan(): void
    {
        $offer = $this->offer();
        $marie = $this->candidate($offer, 'Marie Rakoto', 'marie@example.com');
        $paul = $this->candidate($offer, 'Paul Randria', 'paul@example.com');
        $this->decider();

        $this->schedule($marie, ['mode' => 'phone', 'at' => $this->slot(3, 9, 0), 'duration' => '60']);
        self::assertSame(201, $this->httpStatus());

        $r = $this->schedule($marie, ['mode' => 'phone', 'at' => $this->slot(3, 9, 30), 'duration' => '30']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('job_interview_overlap', $r['error']);

        $this->schedule($marie, ['mode' => 'phone', 'at' => $this->slot(3, 10, 0), 'duration' => '30']);
        self::assertSame(201, $this->httpStatus(), 'Juste après la fin du premier : accepté.');

        $this->schedule($paul, ['mode' => 'phone', 'at' => $this->slot(3, 9, 0), 'duration' => '60']);
        self::assertSame(201, $this->httpStatus(), 'Un autre candidat peut avoir le même créneau (plusieurs recruteurs).');
    }

    public function testChangingAnInterviewWarnsTheCandidateAgain(): void
    {
        $application = $this->candidate($this->offer());
        $this->decider();
        $this->schedule($application, ['mode' => 'phone', 'at' => $this->slot(3, 9, 0)]);
        $id = $this->firstInterview()->getId()->toRfc4122();

        $this->api('POST', '/admin/api/recrutement/entretiens/'.$id, ['action' => 'update', 'mode' => 'in_person', 'at' => $this->slot(5, 11, 15), 'duration' => '30', 'location' => 'Café du Louvre, Analakely']);

        self::assertSame(200, $this->httpStatus());
        $interview = $this->firstInterview();
        self::assertSame(InterviewMode::InPerson, $interview->getMode());
        self::assertSame('Café du Louvre, Analakely', $interview->getLocation());
        // Le transport de test ne garde que les e-mails de la DERNIÈRE requête : ici, celui de la modification.
        self::assertEmailCount(1);
        $mail = self::getMailerMessage(0);
        self::assertEmailTextBodyContains($mail, 'Café du Louvre, Analakely');
        self::assertEmailTextBodyContains($mail, '11:15');
        self::assertStringContainsString('modifié', (string) $mail->getSubject());
    }

    public function testCancellingAnInterviewWarnsTheCandidateAndFreesTheSlot(): void
    {
        $application = $this->candidate($this->offer());
        $this->decider();
        $this->schedule($application, ['mode' => 'phone', 'at' => $this->slot(3, 9, 0), 'duration' => '60']);
        $id = $this->firstInterview()->getId()->toRfc4122();

        $this->api('POST', '/admin/api/recrutement/entretiens/'.$id, ['action' => 'cancel']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(InterviewStatus::Cancelled, $this->firstInterview()->getStatus());
        self::assertEmailCount(1);
        self::assertStringContainsString('annulé', (string) self::getMailerMessage(0)->getSubject());

        $r = $this->api('POST', '/admin/api/recrutement/entretiens/'.$id, ['action' => 'cancel']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('job_interview_not_scheduled', $r['error']);

        $this->schedule($application, ['mode' => 'phone', 'at' => $this->slot(3, 9, 0), 'duration' => '60']);
        self::assertSame(201, $this->httpStatus(), 'Le créneau annulé est de nouveau libre.');
    }

    public function testAnInterviewCanBeMarkedDone(): void
    {
        $application = $this->candidate($this->offer());
        $this->decider();
        $this->schedule($application, ['mode' => 'phone', 'at' => $this->slot(3)]);
        $id = $this->firstInterview()->getId()->toRfc4122();

        $this->api('POST', '/admin/api/recrutement/entretiens/'.$id, ['action' => 'done']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(InterviewStatus::Done, $this->firstInterview()->getStatus());
        self::assertEmailCount(0, 'Aucun e-mail pour un entretien simplement marqué effectué.');
    }

    public function testOnlyWhoCanDecideSchedulesInterviews(): void
    {
        $application = $this->candidate($this->offer());
        $this->signInAdmin($this->adminWith(['recruitment.view', 'recruitment.manage']));

        $this->schedule($application, ['mode' => 'phone', 'at' => $this->slot()]);

        self::assertSame(403, $this->httpStatus());
        self::assertSame(0, $this->em()->getRepository(Interview::class)->count([]));
    }

    public function testAMailFailureStillSchedulesTheInterviewAndTellsTheAdmin(): void
    {
        $application = $this->candidate($this->offer());
        $this->decider();
        // Aucun e-mail ne peut partir : le candidat n'a pas d'adresse exploitable pour le transport de test.
        $this->em()->getConnection()->executeStatement("UPDATE job_applications SET email = 'adresse invalide'");

        $r = $this->schedule($application, ['mode' => 'phone', 'at' => $this->slot()]);

        self::assertSame(201, $this->httpStatus());
        self::assertArrayHasKey('warning', $r, 'L\'administrateur doit être prévenu qu\'il lui faut contacter la personne.');
        self::assertSame(1, $this->em()->getRepository(Interview::class)->count([]));
    }

    // ------------------------------------------------------------------ Planning

    public function testThePlanningListsEveryCandidateWithInterviewsOrderedByApplicationDate(): void
    {
        $offer = $this->offer('Responsable logistique');
        $second = $this->candidate($offer, 'Deuxième Candidat', 'deux@example.com', '-5 days');
        $first = $this->candidate($offer, 'Premier Candidat', 'un@example.com', '-10 days');
        $third = $this->candidate($offer, 'Troisième Candidat', 'trois@example.com', '-1 day');
        $none = $this->candidate($offer, 'Sans Entretien', 'aucun@example.com', '-3 days');
        $this->decider();
        // Les entretiens sont fixés dans un ordre qui n'est PAS celui des candidatures.
        $this->schedule($third, ['mode' => 'phone', 'at' => $this->slot(2, 8, 0)]);
        $this->schedule($first, ['mode' => 'phone', 'at' => $this->slot(6, 15, 0)]);
        $this->schedule($second, ['mode' => 'in_person', 'at' => $this->slot(4, 10, 30), 'location' => 'Bureaux GoBuy']);
        $this->schedule($second, ['mode' => 'phone', 'at' => $this->slot(5, 10, 30)]);

        $crawler = $this->client->request('GET', '/admin/recrutement/planning');
        self::assertResponseIsSuccessful();
        $ids = static fn ($crawler): array => $crawler->filter('[data-planning-candidate]')->each(static fn ($n): string => (string) $n->attr('data-planning-candidate'));

        self::assertSame([$this->appId($first), $this->appId($second), $this->appId($third)], $ids($crawler), 'Du plus ancien au plus récent selon la date de CANDIDATURE.');
        self::assertStringNotContainsString('Sans Entretien', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('[data-planning-candidate="'.$this->appId($second).'"]', 'Bureaux GoBuy');
        self::assertCount(2, $crawler->filter('[data-planning-candidate="'.$this->appId($second).'"] [data-planning-interview]'));
        // La date de candidature et l'heure de chaque entretien (heure de Madagascar) sont affichées.
        self::assertSelectorTextContains('[data-planning-candidate="'.$this->appId($first).'"]', '15:00');
        self::assertSelectorExists('[data-planning-candidate="'.$this->appId($first).'"] [data-applied-at]');

        $crawler = $this->client->request('GET', '/admin/recrutement/planning?order=desc');
        self::assertSame([$this->appId($third), $this->appId($second), $this->appId($first)], $ids($crawler));
    }

    public function testThePlanningFiltersUpcomingInterviewsAndOffers(): void
    {
        $logistics = $this->offer('Logistique');
        $support = $this->offer('Support');
        $a = $this->candidate($logistics, 'Alpha Candidat', 'a@example.com', '-6 days');
        $b = $this->candidate($support, 'Bravo Candidat', 'b@example.com', '-4 days');
        $this->decider();
        $this->schedule($a, ['mode' => 'phone', 'at' => $this->slot(2)]);
        $this->schedule($b, ['mode' => 'phone', 'at' => $this->slot(3)]);
        $this->api('POST', '/admin/api/recrutement/entretiens/'.$this->firstInterview()->getId()->toRfc4122(), ['action' => 'cancel']);

        $crawler = $this->client->request('GET', '/admin/recrutement/planning?scope=upcoming');
        self::assertCount(1, $crawler->filter('[data-planning-candidate]'), 'Un entretien annulé n\'est plus « à venir ».');
        $crawler = $this->client->request('GET', '/admin/recrutement/planning?scope=all');
        self::assertCount(2, $crawler->filter('[data-planning-candidate]'));

        $crawler = $this->client->request('GET', '/admin/recrutement/planning?scope=all&offer='.$this->offerId($support));
        self::assertSame([$this->appId($b)], $crawler->filter('[data-planning-candidate]')->each(static fn ($n): string => (string) $n->attr('data-planning-candidate')));

        $this->client->request('GET', '/admin/recrutement/planning?offer=n-importe-quoi&order=zzz&scope=zzz&page=-4');
        self::assertResponseIsSuccessful();
    }

    public function testThePlanningNeedsTheViewRight(): void
    {
        $this->signInAdmin($this->adminWith(['users.view']));

        $this->client->request('GET', '/admin/recrutement/planning');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMadagascarTimeParsingRejectsImpossibleDates(): void
    {
        self::assertNotNull(RecruitmentTime::parse('2026-11-12T09:30'));
        self::assertNull(RecruitmentTime::parse('2026-02-31T10:00'));
        self::assertNull(RecruitmentTime::parse('12/11/2026 09:30'));
        self::assertSame('2026-11-12T06:30', RecruitmentTime::parse('2026-11-12T09:30')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i'));
    }
}
