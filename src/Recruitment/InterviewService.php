<?php

namespace App\Recruitment;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\Interview;
use App\Entity\JobApplication;
use App\Entity\User;
use App\Enum\InterviewMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * ENTRETIENS (droit « recruitment.decide ») : l'administrateur choisit le mode (téléphone ou sur place), la date, l'heure et la durée.
 *
 *  - par téléphone, l'équipe appelle le numéro donné par le candidat ; sur place, le lieu est obligatoire ;
 *  - la date se saisit à l'HEURE DE MADAGASCAR (RecruitmentTime), doit être à venir et dans l'année ;
 *  - deux entretiens d'un MÊME candidat ne peuvent pas se chevaucher (des candidats différents le peuvent : plusieurs recruteurs) ;
 *  - on ne convoque pas un candidat refusé ou déjà retenu ;
 *  - chaque convocation, changement ou annulation prévient le candidat par e-mail ; le résultat dit si le message est parti.
 *
 * Fixer un premier entretien passe la candidature à « entretien ». Le refus de la candidature annule les entretiens encore prévus.
 */
final class InterviewService
{
    public const MIN_MINUTES = 10;
    public const MAX_MINUTES = 240;
    public const DEFAULT_MINUTES = 30;
    public const NOTE_MAX = 500;
    public const PLACE_MAX = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecruitmentNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $data mode (phone|in_person), at (AAAA-MM-JJTHH:MM, heure de Madagascar), duration (minutes), location, note
     *
     * @return array{interview: Interview, emailed: bool}
     *
     * @throws RecruitmentException
     */
    public function schedule(User $admin, JobApplication $application, array $data): array
    {
        if ($application->getStatus()->isFinal()) {
            throw new RecruitmentException('job_application_final');
        }
        $now = $this->clock->now();
        [$mode, $at, $minutes, $location, $note] = $this->clean($data, $application, null, $now);

        $interview = new Interview($application, $admin, $mode, $at, $minutes, $location, $note, $now);
        $application->addInterview($interview, $now);
        $this->em->persist($interview);
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_INTERVIEW_SCHEDULED, $admin, 'job_application', $application->getId()->toRfc4122(), ['interview' => $interview->getId()->toRfc4122(), 'mode' => $mode->value, 'at' => $at->format(\DATE_ATOM)]);

        return ['interview' => $interview, 'emailed' => $this->notifier->interviewScheduled($interview, false)];
    }

    /**
     * Déplace ou modifie un entretien encore prévu.
     *
     * @param array<string, string> $data
     *
     * @return array{interview: Interview, emailed: bool}
     *
     * @throws RecruitmentException
     */
    public function update(User $admin, Interview $interview, array $data): array
    {
        $application = $interview->getApplication();
        $this->assertScheduled($interview);
        $now = $this->clock->now();
        [$mode, $at, $minutes, $location, $note] = $this->clean($data, $application, $interview, $now);

        $interview->reschedule($mode, $at, $minutes, $location, $note, $now);
        $application->touch($now);
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_INTERVIEW_UPDATED, $admin, 'job_application', $application->getId()->toRfc4122(), ['interview' => $interview->getId()->toRfc4122(), 'mode' => $mode->value, 'at' => $at->format(\DATE_ATOM)]);

        return ['interview' => $interview, 'emailed' => $this->notifier->interviewScheduled($interview, true)];
    }

    /**
     * Annule un entretien encore prévu et prévient le candidat.
     *
     * @return bool vrai si l'e-mail est parti
     *
     * @throws RecruitmentException « job_interview_not_scheduled »
     */
    public function cancel(User $admin, Interview $interview): bool
    {
        $this->assertScheduled($interview);
        $now = $this->clock->now();

        $interview->cancel($now);
        $interview->getApplication()->touch($now);
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_INTERVIEW_CANCELLED, $admin, 'job_application', $interview->getApplication()->getId()->toRfc4122(), ['interview' => $interview->getId()->toRfc4122()]);

        return $this->notifier->interviewCancelled($interview);
    }

    /** Marque l'entretien comme passé (aucun e-mail). @throws RecruitmentException « job_interview_not_scheduled » */
    public function markDone(User $admin, Interview $interview): void
    {
        $this->assertScheduled($interview);
        $now = $this->clock->now();

        $interview->markDone($now);
        $interview->getApplication()->touch($now);
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_INTERVIEW_DONE, $admin, 'job_application', $interview->getApplication()->getId()->toRfc4122(), ['interview' => $interview->getId()->toRfc4122()]);
    }

    private function assertScheduled(Interview $interview): void
    {
        if ($interview->getApplication()->getStatus()->isFinal() || !$interview->isScheduled()) {
            throw new RecruitmentException('job_interview_not_scheduled');
        }
    }

    /**
     * @param array<string, string> $data
     *
     * @return array{0: InterviewMode, 1: \DateTimeImmutable, 2: int, 3: ?string, 4: ?string}
     *
     * @throws RecruitmentException
     */
    private function clean(array $data, JobApplication $application, ?Interview $editing, \DateTimeImmutable $now): array
    {
        $mode = InterviewMode::tryFrom($data['mode'] ?? '') ?? throw new RecruitmentException('job_mode_invalid');
        $at = RecruitmentTime::parse($data['at'] ?? '') ?? throw new RecruitmentException('job_datetime_invalid');
        if ($at < $now) {
            throw new RecruitmentException('job_interview_past');
        }
        if ($at > $now->modify('+1 year')) {
            throw new RecruitmentException('job_interview_too_far');
        }

        $rawMinutes = trim($data['duration'] ?? '');
        $minutes = '' === $rawMinutes ? self::DEFAULT_MINUTES : (preg_match('/^\d{1,3}$/', $rawMinutes) ? (int) $rawMinutes : 0);
        if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
            throw new RecruitmentException('job_interview_duration', ['%min%' => self::MIN_MINUTES, '%max%' => self::MAX_MINUTES]);
        }

        $location = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $data['location'] ?? '')));
        if (InterviewMode::InPerson === $mode && (mb_strlen($location) < 3 || mb_strlen($location) > self::PLACE_MAX)) {
            throw new RecruitmentException('job_interview_place_required', ['%max%' => self::PLACE_MAX]);
        }

        $note = trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', str_replace(["\r\n", "\r"], "\n", $data['note'] ?? '')));
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new RecruitmentException('job_note_too_long', ['%max%' => self::NOTE_MAX]);
        }

        // Un même candidat ne peut pas avoir deux entretiens qui se chevauchent (début < fin de l'autre ET fin > début de l'autre).
        $end = $at->modify('+'.$minutes.' minutes');
        foreach ($application->getInterviews() as $other) {
            if ($other === $editing || !$other->isScheduled()) {
                continue;
            }
            if ($at < $other->getEndsAt() && $end > $other->getScheduledAt()) {
                throw new RecruitmentException('job_interview_overlap');
            }
        }

        return [$mode, $at, $minutes, InterviewMode::InPerson === $mode ? $location : null, '' === $note ? null : $note];
    }
}
