<?php

namespace App\Moderation;

use App\Admin\AdminActionException;
use App\Admin\SupplierReviewService;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\SupplierAppeal;
use App\Entity\SupplierAppealDocument;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\SupplierStatus;
use App\Notification\AppealNotifier;
use App\Notification\ModerationNotifier;
use App\Repository\SupplierAppealRepository;
use App\Repository\SupplierProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * CONTESTATION d'un refus (ou d'un retrait d'approbation) par le fournisseur, puis décision de l'équipe.
 *
 * Fournisseur : il écrit un message (20 à 4000 caractères) et peut joindre jusqu'à 5 pièces (PDF, JPEG, PNG ; 2 Mo chacune,
 * 6 Mo au total : les limites par défaut de PHP, upload_max_filesize = 2M et post_max_size = 8M, suffisent). Règles : le dossier doit être « refusé » ou « suspendu » ; une seule contestation ouverte à la fois ;
 * 3 contestations au plus par décision (ensuite la décision est définitive tant que l'équipe ne la rouvre pas) ; le compte
 * doit être actif. L'équipe est prévenue par e-mail (message + pièces) ; le fournisseur reçoit un accusé de réception.
 *
 * Pièces jointes : le type est lu dans le CONTENU du fichier (le type annoncé par le navigateur est ignoré), le nom est
 * assaini, l'extension est imposée par le type détecté. Elles ne sont jamais affichées : elles se téléchargent.
 *
 * Équipe (droit « suppliers.approve ») : accepter = re-valider le dossier (SupplierReviewService::approve) ; rejeter = maintenir
 * la décision, avec une explication obligatoire montrée au fournisseur.
 */
final class SupplierAppealService
{
    public const MAX_FILES = 5;
    public const MAX_FILE_BYTES = 2 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 6 * 1024 * 1024;
    public const MAX_PER_DECISION = 3;
    public const MESSAGE_MIN = 20;
    public const MESSAGE_MAX = 4000;

    /** Types acceptés (déterminés par le contenu) et extension imposée. */
    private const ALLOWED = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupplierAppealRepository $appeals,
        private readonly SupplierProfileRepository $profiles,
        private readonly SupplierReviewService $review,
        private readonly AppealNotifier $teamNotifier,
        private readonly ModerationNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Raison pour laquelle ce fournisseur ne peut PAS contester maintenant, ou null s'il le peut. Sert à la page (afficher ou
     * masquer le formulaire) ET à submit() : une seule règle.
     */
    public function refusalFor(User $supplier, ?SupplierProfile $profile): ?string
    {
        if (!$supplier->isSupplier() || null === $profile) {
            return 'appeal_not_allowed';
        }
        if (!$supplier->isActive()) {
            return 'appeal_not_allowed';
        }
        if (!\in_array($profile->getStatus(), [SupplierStatus::Rejected, SupplierStatus::Suspended], true)) {
            return 'appeal_not_needed';
        }
        if ([] !== $this->appeals->findOpenForSupplier($supplier)) {
            return 'appeal_open';
        }
        $since = $profile->getReviewedAt() ?? $profile->getSubmittedAt();
        if ($this->appeals->countSince($supplier, $since) >= self::MAX_PER_DECISION) {
            return 'appeal_limit';
        }

        return null;
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @throws ModerationException
     */
    public function submit(User $supplier, string $message, array $files): SupplierAppeal
    {
        $message = trim($message);
        $length = mb_strlen($message);
        if ($length < self::MESSAGE_MIN || $length > self::MESSAGE_MAX) {
            throw new ModerationException('appeal_message_length', ['%min%' => self::MESSAGE_MIN, '%max%' => self::MESSAGE_MAX]);
        }
        $documents = $this->readFiles($files);

        // Contrôles + création dans UNE transaction, sous verrou : deux dépôts simultanés ne peuvent pas dépasser la limite
        // « une contestation ouverte à la fois ». Le refus est levé APRÈS la transaction (une exception qui la traverse fermerait
        // l'EntityManager).
        $result = $this->em->wrapInTransaction(function () use ($supplier, $message, $documents): SupplierAppeal|string {
            $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:k))', ['k' => 'appeal|'.$supplier->getId()->toRfc4122()]);
            $profile = $this->profiles->findOneByUser($supplier);
            if (null !== $refusal = $this->refusalFor($supplier, $profile)) {
                return $refusal;
            }
            \assert($profile instanceof SupplierProfile);

            $now = $this->clock->now();
            $appeal = new SupplierAppeal($supplier, $message, $profile->getStatus()->value, $profile->getRejectionReason(), $now);
            foreach ($documents as $document) {
                $appeal->addDocument(new SupplierAppealDocument($appeal, $document['name'], $document['type'], $document['content'], $now));
            }
            $this->em->persist($appeal);
            $this->em->flush();

            return $appeal;
        });
        if (\is_string($result)) {
            throw new ModerationException($result, 'appeal_limit' === $result ? ['%count%' => self::MAX_PER_DECISION] : []);
        }

        $profile = $this->profiles->findOneByUser($supplier);
        $this->audit->record(AuditAction::APPEAL_SUBMITTED, $supplier, 'user', $supplier->getId()->toRfc4122(), [
            'appeal' => $result->getId()->toRfc4122(),
            'documents' => \count($result->getDocuments()),
            'contested' => $result->getContestedStatus(),
        ]);
        // Hors transaction : un serveur d'e-mails en panne n'annule pas le dépôt (la contestation reste visible dans l'administration).
        if (null !== $profile) {
            $this->teamNotifier->notifyTeam($result, $profile);
        }
        $this->notifier->notify($supplier, 'appeal_received');

        return $result;
    }

    /**
     * Décision de l'équipe sur une contestation ouverte.
     *
     * @param 'accept'|'dismiss'|string $action
     *
     * @throws ModerationException|AdminActionException
     */
    public function decide(User $admin, SupplierAppeal $appeal, string $action, string $note): void
    {
        if (!$appeal->isOpen()) {
            throw new ModerationException('appeal_closed');
        }
        $note = trim($note);
        $supplier = $appeal->getSupplier();

        if ('accept' === $action) {
            $profile = $this->profiles->findOneByUser($supplier) ?? throw new AdminActionException('not_found');
            // approve() re-valide le dossier ET clôt cette contestation (et les autres ouvertes) comme acceptées.
            $this->review->approve($admin, $profile, '' !== $note ? mb_substr($note, 0, 500) : null);

            return;
        }
        if ('dismiss' !== $action) {
            throw new ModerationException('invalid_action');
        }
        if (mb_strlen($note) < 5) {
            throw new ModerationException('reason_required');
        }

        $appeal->dismiss($admin, $note, $this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::APPEAL_DISMISSED, $admin, 'user', $supplier->getId()->toRfc4122(), ['appeal' => $appeal->getId()->toRfc4122(), 'note' => mb_substr($note, 0, 500)]);
        $this->notifier->notify($supplier, 'appeal_dismissed', ['%detail%' => mb_substr($note, 0, 500)]);
    }

    /**
     * Lit et contrôle les fichiers envoyés.
     *
     * @param list<UploadedFile> $files
     *
     * @return list<array{name: string, type: string, content: string}>
     *
     * @throws ModerationException
     */
    private function readFiles(array $files): array
    {
        $files = array_values(array_filter($files, static fn (mixed $f): bool => $f instanceof UploadedFile));
        if (\count($files) > self::MAX_FILES) {
            throw new ModerationException('appeal_too_many_files', ['%max%' => self::MAX_FILES]);
        }

        $documents = [];
        $total = 0;
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        foreach ($files as $file) {
            if (\UPLOAD_ERR_NO_FILE === $file->getError()) {
                continue;
            }
            if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                throw new ModerationException('appeal_file_too_large', ['%mb%' => (int) (self::MAX_FILE_BYTES / 1048576)]);
            }
            if (!$file->isValid()) {
                throw new ModerationException('appeal_file_invalid');
            }
            $size = (int) $file->getSize();
            if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
                throw new ModerationException($size <= 0 ? 'appeal_file_invalid' : 'appeal_file_too_large', ['%mb%' => (int) (self::MAX_FILE_BYTES / 1048576)]);
            }
            $content = (string) file_get_contents($file->getPathname());
            // Le type vient du CONTENU : un « .pdf » qui n'en est pas un, ou un exécutable renommé, est refusé.
            $type = (string) $finfo->buffer($content);
            if (!isset(self::ALLOWED[$type])) {
                throw new ModerationException('appeal_file_type');
            }
            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new ModerationException('appeal_files_total', ['%mb%' => (int) (self::MAX_TOTAL_BYTES / 1048576)]);
            }
            $documents[] = ['name' => $this->safeName($file->getClientOriginalName(), self::ALLOWED[$type]), 'type' => $type, 'content' => $content];
        }

        return $documents;
    }

    /** Nom de fichier sans chemin, sans caractère spécial, de longueur raisonnable, avec l'extension imposée par le type détecté. */
    private function safeName(string $original, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $original), \PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N}._() -]+/u', '_', $base), " ._-");
        $base = mb_substr('' !== $base ? $base : 'piece', 0, 100);

        return $base.'.'.$extension;
    }
}
