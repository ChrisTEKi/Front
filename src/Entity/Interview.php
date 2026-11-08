<?php

namespace App\Entity;

use App\Enum\InterviewMode;
use App\Enum\InterviewStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Entretien fixé avec un candidat — table `interviews`.
 *
 * Un candidat peut avoir plusieurs entretiens (ex. un appel puis une rencontre sur place). Par téléphone, l'équipe appelle le numéro
 * du candidat ; sur place, le lieu est obligatoire. L'heure est stockée avec son fuseau (instant absolu) ; l'affichage se fait à
 * l'heure de Madagascar (voir RecruitmentTime).
 */
#[ORM\Entity]
#[ORM\Table(name: 'interviews', options: ['comment' => 'Entretiens fixés avec les candidats (téléphone ou sur place).'])]
#[ORM\Index(name: 'idx_interviews_application', columns: ['application_id', 'scheduled_at'])]
#[ORM\Index(name: 'idx_interviews_when', columns: ['scheduled_at'])]
class Interview
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: JobApplication::class, inversedBy: 'interviews')]
    #[ORM\JoinColumn(name: 'application_id', nullable: false, onDelete: 'CASCADE')]
    private JobApplication $application;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: InterviewMode::class)]
    private InterviewMode $mode;

    #[ORM\Column(name: 'scheduled_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column(name: 'duration_minutes', type: Types::SMALLINT)]
    private int $durationMinutes;

    /** Adresse du rendez-vous (obligatoire sur place, sans objet par téléphone). */
    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $location;

    /** Consignes montrées au candidat (documents à apporter, personne à demander…). */
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $note;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: InterviewStatus::class)]
    private InterviewStatus $status = InterviewStatus::Scheduled;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(JobApplication $application, User $createdBy, InterviewMode $mode, \DateTimeImmutable $scheduledAt, int $durationMinutes, ?string $location, ?string $note, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->application = $application;
        $this->createdBy = $createdBy;
        $this->mode = $mode;
        $this->scheduledAt = $scheduledAt;
        $this->durationMinutes = $durationMinutes;
        $this->location = InterviewMode::InPerson === $mode ? $location : null;
        $this->note = $note;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function reschedule(InterviewMode $mode, \DateTimeImmutable $scheduledAt, int $durationMinutes, ?string $location, ?string $note, \DateTimeImmutable $now): void
    {
        $this->mode = $mode;
        $this->scheduledAt = $scheduledAt;
        $this->durationMinutes = $durationMinutes;
        $this->location = InterviewMode::InPerson === $mode ? $location : null;
        $this->note = $note;
        $this->updatedAt = $now;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $this->status = InterviewStatus::Cancelled;
        $this->updatedAt = $now;
    }

    public function markDone(\DateTimeImmutable $now): void
    {
        $this->status = InterviewStatus::Done;
        $this->updatedAt = $now;
    }

    public function isScheduled(): bool
    {
        return InterviewStatus::Scheduled === $this->status;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getApplication(): JobApplication
    {
        return $this->application;
    }

    public function getMode(): InterviewMode
    {
        return $this->mode;
    }

    public function getScheduledAt(): \DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->scheduledAt->modify('+'.$this->durationMinutes.' minutes');
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getStatus(): InterviewStatus
    {
        return $this->status;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
