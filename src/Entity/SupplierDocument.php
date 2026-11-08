<?php

namespace App\Entity;

use App\Enum\SupplierDocumentKind;
use App\Repository\SupplierDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Pièce justificative d'un fournisseur (pièce d'identité, NIF / STAT ; PDF, JPEG ou PNG) — table `supplier_documents`.
 *
 * Même principe que les pièces de contestation et les CV : contenu en `bytea` dans PostgreSQL (sauvegardé avec le compte, effacé
 * avec lui : clé étrangère « ON DELETE CASCADE »), type déterminé par le SERVEUR en lisant le contenu, jamais affiché dans une
 * page (téléchargement « attachment »). Données personnelles : lecture réservée aux administrateurs ayant le droit
 * « suppliers.approve » (valider les fournisseurs), chaque ouverture est inscrite au journal d'audit.
 */
#[ORM\Entity(repositoryClass: SupplierDocumentRepository::class)]
#[ORM\Table(name: 'supplier_documents', options: ['comment' => 'Pièce d\'identité et justificatif NIF/STAT des fournisseurs (contenu en bytea).'])]
#[ORM\UniqueConstraint(name: 'uniq_supplier_document_kind', columns: ['user_id', 'kind'])]
class SupplierDocument
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Le compte fournisseur propriétaire. Supprimé avec lui. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: SupplierDocumentKind::class)]
    private SupplierDocumentKind $kind;

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

    public function __construct(User $owner, SupplierDocumentKind $kind, string $fileName, string $contentType, string $content, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
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

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getKind(): SupplierDocumentKind
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
