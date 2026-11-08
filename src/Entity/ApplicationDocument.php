<?php

namespace App\Entity;

use App\Enum\DocumentKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Pièce d'une candidature (CV ou lettre de motivation ; PDF, JPEG ou PNG) — table `application_documents`.
 *
 * Même principe que les pièces de contestation : contenu en `bytea` dans PostgreSQL (sauvegardé et supprimé avec la candidature),
 * type déterminé par le SERVEUR en lisant le contenu, jamais affiché dans une page (téléchargement « attachment »).
 */
#[ORM\Entity]
#[ORM\Table(name: 'application_documents', options: ['comment' => 'CV et lettres de motivation des candidatures (contenu en bytea).'])]
#[ORM\UniqueConstraint(name: 'uniq_application_document_kind', columns: ['application_id', 'kind'])]
class ApplicationDocument
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: JobApplication::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(name: 'application_id', nullable: false, onDelete: 'CASCADE')]
    private JobApplication $application;

    #[ORM\Column(type: Types::STRING, length: 12, enumType: DocumentKind::class)]
    private DocumentKind $kind;

    #[ORM\Column(name: 'file_name', type: Types::STRING, length: 150)]
    private string $fileName;

    #[ORM\Column(name: 'content_type', type: Types::STRING, length: 100)]
    private string $contentType;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    #[ORM\Column(type: Types::BLOB)]
    private mixed $content;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(JobApplication $application, DocumentKind $kind, string $fileName, string $contentType, string $content, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->application = $application;
        $this->kind = $kind;
        $this->fileName = $fileName;
        $this->contentType = $contentType;
        $this->size = \strlen($content);
        $this->content = $content;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getApplication(): JobApplication
    {
        return $this->application;
    }

    public function getKind(): DocumentKind
    {
        return $this->kind;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getContent(): string
    {
        // Avec PostgreSQL, une colonne bytea est lue comme un « flux » ; juste après un enregistrement, c'est une chaîne.
        if (\is_resource($this->content)) {
            rewind($this->content);

            return (string) stream_get_contents($this->content);
        }

        return (string) $this->content;
    }
}
