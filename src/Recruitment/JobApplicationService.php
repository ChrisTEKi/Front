<?php

namespace App\Recruitment;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\ApplicationDocument;
use App\Entity\JobApplication;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Enum\ApplicationStatus;
use App\Enum\DocumentKind;
use App\Phone\PhoneNumberParser;
use App\Repository\JobApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * CANDIDATURES : dépôt par un visiteur (sans compte), puis décisions de l'équipe.
 *
 * Dépôt : nom, e-mail, téléphone (pour les entretiens), message facultatif, un CV (obligatoire) et une lettre de motivation
 * (facultative) en PDF, JPEG ou PNG (2 Mo chacun, 4 Mo au total). Le type de chaque fichier est lu dans son CONTENU (le type annoncé par
 * le navigateur est ignoré), son nom est assaini. Règles : l'offre doit être ouverte ; une personne ne postule qu'UNE fois par offre
 * (sous verrou : deux envois simultanés ne passent pas tous les deux) ; 8 dépôts par heure et par adresse IP.
 *
 * Décisions (droit « recruitment.decide ») : mettre « en cours d'examen », REFUSER (e-mail automatique adressé au nom du candidat,
 * entretiens encore prévus annulés), marquer « retenu » (repère interne, aucun e-mail), effacer le candidat (mot de passe redemandé).
 *
 * Conservation : voir RecruitmentPurger (24 mois après la dernière activité, puis effacement automatique).
 */
final class JobApplicationService
{
    public const MAX_FILE_BYTES = 2 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 4 * 1024 * 1024;
    public const MESSAGE_MAX = 2000;
    public const NOTE_MAX = 500;

    /** Types acceptés (déterminés par le contenu) et extension imposée. */
    private const ALLOWED = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JobApplicationRepository $applications,
        private readonly PhoneNumberParser $phones,
        private readonly RecruitmentNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $jobApplicationIpLimiter,
    ) {
    }

    // ------------------------------------------------------------------ Dépôt par le candidat

    /**
     * @param array{name?: string, email?: string, phone?: string, message?: string} $fields
     *
     * @throws RecruitmentException
     */
    public function apply(JobOffer $offer, array $fields, ?UploadedFile $cv, ?UploadedFile $letter, string $locale, string $ip): JobApplication
    {
        if (!$offer->acceptsApplications($this->clock->now())) {
            throw new RecruitmentException('job_offer_closed');
        }
        $limit = $this->jobApplicationIpLimiter->create($ip)->consume();
        if (!$limit->isAccepted()) {
            throw new RecruitmentException('job_rate_limited', ['%minutes%' => max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()) / 60))]);
        }

        $name = $this->name($fields['name'] ?? '');
        $email = $this->email($fields['email'] ?? '');
        $phone = $this->phones->parse($fields['phone'] ?? '')?->e164 ?? throw new RecruitmentException('job_phone_invalid');
        $message = $this->block($fields['message'] ?? '');
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            throw new RecruitmentException('job_message_length', ['%max%' => self::MESSAGE_MAX]);
        }
        if (!$this->hasFile($cv)) {
            throw new RecruitmentException('job_cv_required');
        }
        $locale = \in_array($locale, ['fr', 'en', 'es'], true) ? $locale : 'fr';

        $documents = [];
        $total = 0;
        foreach ([[DocumentKind::Cv, $cv], [DocumentKind::CoverLetter, $letter]] as [$kind, $file]) {
            if ($this->hasFile($file)) {
                $documents[] = $this->read($kind, $file, $total);
            }
        }

        // Contrôle du doublon + création dans UNE transaction, sous verrou. Le refus est levé APRÈS la transaction (une exception qui
        // la traverse fermerait l'EntityManager).
        $result = $this->em->wrapInTransaction(function () use ($offer, $name, $email, $phone, $message, $locale, $documents): JobApplication|string {
            $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:k))', ['k' => 'job|'.$offer->getId()->toRfc4122().'|'.$email]);
            if (null !== $this->applications->findOneByOfferAndEmail($offer, $email)) {
                return 'job_already_applied';
            }
            $now = $this->clock->now();
            $application = new JobApplication($offer, $name, $email, $phone, '' === $message ? null : $message, $locale, $now);
            foreach ($documents as $document) {
                $application->addDocument(new ApplicationDocument($application, $document['kind'], $document['name'], $document['type'], $document['content'], $now));
            }
            $this->em->persist($application);
            $this->em->flush();

            return $application;
        });
        if (\is_string($result)) {
            throw new RecruitmentException($result);
        }

        $this->audit->record(AuditAction::JOB_APPLICATION_RECEIVED, null, 'job_application', $result->getId()->toRfc4122(), ['offer' => $offer->getId()->toRfc4122(), 'documents' => \count($documents)]);
        // Hors transaction : un serveur d'e-mails en panne n'annule pas le dépôt (la candidature reste visible dans l'administration).
        $this->notifier->notifyTeam($result);
        $this->notifier->applicationReceived($result);

        return $result;
    }

    // ------------------------------------------------------------------ Décisions de l'équipe

    /** « Nouvelle » → « en cours d'examen » : ouvrir la fiche suffit à le faire, sans trace superflue si c'est déjà fait. */
    public function startReview(User $admin, JobApplication $application): void
    {
        if (ApplicationStatus::New !== $application->getStatus()) {
            return;
        }
        $application->startReview($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_APPLICATION_REVIEWED, $admin, 'job_application', $application->getId()->toRfc4122(), ['offer' => $application->getOffer()->getId()->toRfc4122()]);
    }

    /**
     * Refuse la candidature : e-mail automatique adressé au nom du candidat (avec, si l'administrateur l'a écrite, une phrase de sa part).
     * Les entretiens encore prévus sont annulés sans e-mail distinct : le message de refus suffit.
     *
     * @return bool vrai si l'e-mail est parti
     *
     * @throws RecruitmentException « job_already_refused », « job_application_final », « job_note_too_long »
     */
    public function refuse(User $admin, JobApplication $application, string $note): bool
    {
        if (ApplicationStatus::Refused === $application->getStatus()) {
            throw new RecruitmentException('job_already_refused');
        }
        $this->assertOpen($application);
        $note = $this->block($note);
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new RecruitmentException('job_note_too_long', ['%max%' => self::NOTE_MAX]);
        }

        $now = $this->clock->now();
        $cancelled = 0;
        foreach ($application->getInterviews() as $interview) {
            if ($interview->isScheduled()) {
                $interview->cancel($now);
                ++$cancelled;
            }
        }
        $application->refuse($admin, '' === $note ? null : $note, $now);
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_APPLICATION_REFUSED, $admin, 'job_application', $application->getId()->toRfc4122(), [
            'offer' => $application->getOffer()->getId()->toRfc4122(),
            'with_note' => '' !== $note,
            'cancelled_interviews' => $cancelled,
        ]);

        return $this->notifier->refused($application);
    }

    /** Repère interne « retenu » (aucun e-mail). @throws RecruitmentException « job_application_final » */
    public function markHired(User $admin, JobApplication $application): void
    {
        $this->assertOpen($application);

        $application->markHired($admin, $this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_APPLICATION_HIRED, $admin, 'job_application', $application->getId()->toRfc4122(), ['offer' => $application->getOffer()->getId()->toRfc4122()]);
    }

    /** Efface définitivement le candidat, ses pièces et ses entretiens (demande d'effacement, erreur de saisie…). */
    public function delete(User $admin, JobApplication $application): void
    {
        $id = $application->getId()->toRfc4122();
        $offer = $application->getOffer()->getId()->toRfc4122();
        $this->em->remove($application);
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_APPLICATION_DELETED, $admin, 'job_application', $id, ['offer' => $offer]);
    }

    /** Lecture d'une pièce : tracée (qui, quand, quelle candidature) car elle contient des données personnelles. */
    public function recordDocumentView(User $admin, ApplicationDocument $document): void
    {
        $this->audit->record(AuditAction::JOB_DOCUMENT_VIEWED, $admin, 'job_application', $document->getApplication()->getId()->toRfc4122(), ['kind' => $document->getKind()->value]);
    }

    /** Une décision définitive (refusé ou retenu) ne se défait pas par une nouvelle action. */
    private function assertOpen(JobApplication $application): void
    {
        if ($application->getStatus()->isFinal()) {
            throw new RecruitmentException('job_application_final');
        }
    }

    // ------------------------------------------------------------------ Contrôle des champs et des fichiers

    private function hasFile(?UploadedFile $file): bool
    {
        return null !== $file && \UPLOAD_ERR_NO_FILE !== $file->getError();
    }

    /**
     * @return array{kind: DocumentKind, name: string, type: string, content: string}
     *
     * @throws RecruitmentException
     */
    private function read(DocumentKind $kind, UploadedFile $file, int &$total): array
    {
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw new RecruitmentException('job_file_too_large', ['%mb%' => (int) (self::MAX_FILE_BYTES / 1048576)]);
        }
        if (!$file->isValid()) {
            throw new RecruitmentException('job_file_invalid');
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new RecruitmentException($size <= 0 ? 'job_file_invalid' : 'job_file_too_large', ['%mb%' => (int) (self::MAX_FILE_BYTES / 1048576)]);
        }
        $content = (string) file_get_contents($file->getPathname());
        // Le type vient du CONTENU : un « .pdf » qui n'en est pas un, ou un exécutable renommé, est refusé.
        $type = (string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content);
        if (!isset(self::ALLOWED[$type])) {
            throw new RecruitmentException('job_file_type');
        }
        $total += $size;
        if ($total > self::MAX_TOTAL_BYTES) {
            throw new RecruitmentException('job_files_total', ['%mb%' => (int) (self::MAX_TOTAL_BYTES / 1048576)]);
        }

        return ['kind' => $kind, 'name' => $this->safeName($file->getClientOriginalName(), self::ALLOWED[$type], $kind), 'type' => $type, 'content' => $content];
    }

    /** Nom de fichier sans chemin, sans caractère spécial, de longueur raisonnable, avec l'extension imposée par le type détecté. */
    private function safeName(string $original, string $extension, DocumentKind $kind): string
    {
        $base = pathinfo(str_replace('\\', '/', $original), \PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N}._() -]+/u', '_', $base), ' ._-');
        $base = mb_substr('' !== $base ? $base : (DocumentKind::Cv === $kind ? 'cv' : 'lettre'), 0, 100);

        return $base.'.'.$extension;
    }

    /** Nom : 2 à 120 caractères sur une ligne, avec au moins une lettre (il part dans un sujet d'e-mail et dans le nom du destinataire). */
    private function name(string $raw): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw)));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !preg_match('/\p{L}/u', $name) || preg_match('/[<>@]/', $name)) {
            throw new RecruitmentException('job_name_invalid');
        }

        return $name;
    }

    private function email(string $raw): string
    {
        $email = mb_strtolower(trim($raw));
        if ('' === $email || mb_strlen($email) > 180 || preg_match('/[\r\n]/', $email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new RecruitmentException('job_email_invalid');
        }

        return $email;
    }

    private function block(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
