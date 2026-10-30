<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Photo de profil d'un compte — table `user_avatars` (une ligne au plus par compte).
 *
 * Pourquoi dans PostgreSQL et pas dans un dossier du serveur ?
 *   - elle suit le compte partout (sauvegardes, copie de la base, plusieurs serveurs web sans dossier partagé) ;
 *   - elle disparaît AVEC le compte : la clé étrangère `ON DELETE CASCADE` l'efface quand la purge supprime l'utilisateur,
 *     donc la suppression définitive des données personnelles n'oublie pas la photo ;
 *   - l'image est déjà réduite (512 × 512, JPEG, ~40 Ko) par AvatarImageProcessor avant d'arriver ici.
 *
 * Elle vit dans une table à part (et non dans `users`) pour que les requêtes ordinaires sur les comptes ne chargent jamais
 * les octets de l'image.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_avatars', options: ['comment' => 'Photo de profil (image JPEG 512x512 ré-encodée par le serveur). Supprimée avec le compte (cascade).'])]
class UserAvatar
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Octets de l'image (type `bytea`). PostgreSQL les rend sous forme de « flux » : voir getBytes(). */
    #[ORM\Column(type: Types::BLOB)]
    private mixed $bytes;

    /** Type MIME de l'image enregistrée (toujours image/jpeg : le serveur ré-encode tout). */
    #[ORM\Column(name: 'mime_type', type: Types::STRING, length: 32)]
    private string $mimeType;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $bytes, string $mimeType, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->bytes = $bytes;
        $this->mimeType = $mimeType;
        $this->updatedAt = $now;
    }

    /** Remplace l'image (une nouvelle photo remplace l'ancienne : même ligne, jamais deux photos pour un compte). */
    public function replace(string $bytes, string $mimeType, \DateTimeImmutable $now): void
    {
        $this->bytes = $bytes;
        $this->mimeType = $mimeType;
        $this->updatedAt = $now;
    }

    public function getBytes(): string
    {
        // Avec PostgreSQL, une colonne bytea est lue comme un « flux » (resource) ; juste après un enregistrement, c'est une chaîne.
        if (\is_resource($this->bytes)) {
            rewind($this->bytes);

            return (string) stream_get_contents($this->bytes);
        }

        return (string) $this->bytes;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
