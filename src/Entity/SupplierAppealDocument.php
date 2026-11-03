<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Pièce jointe d'une contestation (PDF, JPEG ou PNG) — table `supplier_appeal_documents`.
 *
 * Le contenu est stocké dans PostgreSQL (colonne bytea) : sauvegardé et supprimé avec la contestation. Le type est déterminé
 * par le SERVEUR en lisant le contenu du fichier (jamais celui annoncé par le navigateur). Les pièces ne sont jamais affichées
 * dans une page : elles se téléchargent (en-tête « attachment »).
 */
#[ORM\Entity]
#[ORM\Table(name: 'supplier_appeal_documents', options: ['comment' => 'Pièces jointes des contestations de fournisseurs (contenu en bytea).'])]
class SupplierAppealDocument
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: SupplierAppeal::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(name: 'appeal_id', nullable: false, onDelete: 'CASCADE')]
    private SupplierAppeal $appeal;

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

    public function __construct(SupplierAppeal $appeal, string $fileName, string $contentType, string $content, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->appeal = $appeal;
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

    public function getAppeal(): SupplierAppeal
    {
        return $this->appeal;
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
