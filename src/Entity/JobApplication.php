<?php

namespace App\Entity;

use App\Enum\ApplicationStatus;
use App\Enum\DocumentKind;
use App\Repository\JobApplicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Candidature d'une personne à une offre d'emploi — table `job_applications`.
 *
 * Le candidat n'a PAS de compte GoBuy : on garde son nom, son e-mail, son téléphone (pour les entretiens), un message facultatif
 * et ses pièces (CV, lettre de motivation). Une personne ne postule qu'une fois par offre (unicité offre + e-mail).
 * La langue du visiteur est mémorisée : les e-mails qui lui sont envoyés (accusé de réception, refus, convocation) l'utilisent.
 *
 * Données personnelles : lecture réservée aux administrateurs ayant le droit « recruitment.view » (chaque ouverture d'une pièce est
 * tracée), effacement par un administrateur ou automatique après la durée de conservation (voir RecruitmentPurger).
 */
#[ORM\Entity(repositoryClass: JobApplicationRepository::class)]
#[ORM\Table(name: 'job_applications', options: ['comment' => 'Candidatures aux offres d\'emploi (candidats sans compte).'])]
#[ORM\UniqueConstraint(name: 'uniq_job_application_offer_email', columns: ['offer_id', 'email'])]
#[ORM\Index(name: 'idx_job_applications_offer', columns: ['offer_id', 'created_at'])]
#[ORM\Index(name: 'idx_job_applications_status', columns: ['status'])]
class JobApplication
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: JobOffer::class)]
    #[ORM\JoinColumn(name: 'offer_id', nullable: false, onDelete: 'RESTRICT')]
    private JobOffer $offer;

    #[ORM\Column(name: 'full_name', type: Types::STRING, length: 120)]
    private string $fullName;

    /** En minuscules : l'unicité (offre + e-mail) ne dépend pas de la casse. */
    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    /** Format international (E.164) : les entretiens par téléphone appellent ce numéro. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $phone;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message;

    #[ORM\Column(type: Types::STRING, length: 2)]
    private string $locale;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: ApplicationStatus::class)]
    private ApplicationStatus $status = ApplicationStatus::New;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    /** Phrase facultative ajoutée par l'administrateur à l'e-mail de refus (montrée au candidat). */
    #[ORM\Column(name: 'refusal_note', type: Types::STRING, length: 500, nullable: true)]
    private ?string $refusalNote = null;

    /** @var Collection<int, ApplicationDocument> */
    #[ORM\OneToMany(targetEntity: ApplicationDocument::class, mappedBy: 'application', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['kind' => 'ASC'])]
    private Collection $documents;

    /** @var Collection<int, Interview> */
    #[ORM\OneToMany(targetEntity: Interview::class, mappedBy: 'application', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['scheduledAt' => 'ASC'])]
    private Collection $interviews;

    public function __construct(JobOffer $offer, string $fullName, string $email, string $phone, ?string $message, string $locale, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->offer = $offer;
        $this->fullName = $fullName;
        $this->email = mb_strtolower($email);
        $this->phone = $phone;
        $this->message = $message;
        $this->locale = $locale;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->documents = new ArrayCollection();
        $this->interviews = new ArrayCollection();
    }

    public function addDocument(ApplicationDocument $document): void
    {
        $this->documents->add($document);
    }

    public function addInterview(Interview $interview, \DateTimeImmutable $now): void
    {
        $this->interviews->add($interview);
        if (!$this->status->isFinal()) {
            $this->status = ApplicationStatus::Interview;
        }
        $this->updatedAt = $now;
    }

    /** « Nouvelle » → « en cours d'examen » (sans effet sur une décision déjà prise ou un entretien déjà fixé). */
    public function startReview(\DateTimeImmutable $now): void
    {
        if (ApplicationStatus::New === $this->status) {
            $this->status = ApplicationStatus::Reviewing;
            $this->updatedAt = $now;
        }
    }

    public function refuse(User $admin, ?string $note, \DateTimeImmutable $now): void
    {
        $this->status = ApplicationStatus::Refused;
        $this->decidedAt = $now;
        $this->decidedBy = $admin;
        $this->refusalNote = null !== $note && '' !== trim($note) ? mb_substr(trim($note), 0, 500) : null;
        $this->updatedAt = $now;
    }

    public function markHired(User $admin, \DateTimeImmutable $now): void
    {
        $this->status = ApplicationStatus::Hired;
        $this->decidedAt = $now;
        $this->decidedBy = $admin;
        $this->refusalNote = null;
        $this->updatedAt = $now;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOffer(): JobOffer
    {
        return $this->offer;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getStatus(): ApplicationStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getRefusalNote(): ?string
    {
        return $this->refusalNote;
    }

    /** @return Collection<int, ApplicationDocument> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function getDocument(DocumentKind $kind): ?ApplicationDocument
    {
        foreach ($this->documents as $document) {
            if ($document->getKind() === $kind) {
                return $document;
            }
        }

        return null;
    }

    /** @return Collection<int, Interview> */
    public function getInterviews(): Collection
    {
        return $this->interviews;
    }

    public function findInterview(string $id): ?Interview
    {
        foreach ($this->interviews as $interview) {
            if ($interview->getId()->toRfc4122() === $id) {
                return $interview;
            }
        }

        return null;
    }
}
