<?php

namespace App\Entity;

use App\Enum\Permission;
use App\Repository\AdminRoleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Profil d'administrateur (« Super Admin », « Admin Finance », « Admin Compta »…) — table `admin_roles`.
 *
 * Un profil regroupe un ensemble de droits (voir l'enum Permission). Le Super Admin crée autant
 * de profils qu'il veut, puis assigne un profil à chaque administrateur. Il peut ensuite ajouter
 * ou retirer des droits individuellement (voir AdminProfile).
 *
 * Le profil « super_admin » (`is_system = true`) est créé par la migration : il ne peut ni être
 * modifié ni être supprimé depuis l'interface.
 */
#[ORM\Entity(repositoryClass: AdminRoleRepository::class)]
#[ORM\Table(name: 'admin_roles', options: ['comment' => 'Profils d\'administrateurs (Super Admin, Admin Finance, Admin Compta…) = ensembles de droits fins.'])]
#[ORM\UniqueConstraint(name: 'uniq_admin_roles_code', columns: ['code'])]
class AdminRole
{
    /** Code technique du profil réservé au Super Admin. */
    public const CODE_SUPER_ADMIN = 'super_admin';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Identifiant technique unique, en minuscules (ex. « finance », « compta »). */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $code;

    /** Nom affiché (ex. « Admin Finance »). */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Droits du profil : liste de chaînes de l'enum Permission, stockée en JSONB.
     * Le profil super_admin contient TOUS les droits.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['comment' => 'Droits du profil (valeurs de l\'enum Permission)', 'jsonb' => true])]
    private array $permissions = [];

    /** Profil système (non modifiable, non supprimable). */
    #[ORM\Column(name: 'is_system', type: Types::BOOLEAN, options: ['comment' => 'Profil système : non modifiable / non supprimable', 'default' => false])]
    private bool $isSystem = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<Permission> $permissions
     */
    public function __construct(string $code, string $label, ?string $description, array $permissions, bool $isSystem, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->code = mb_strtolower(trim($code));
        $this->label = trim($label);
        $this->description = $description;
        $this->setPermissions($permissions);
        $this->isSystem = $isSystem;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function isSuperAdminRole(): bool
    {
        return self::CODE_SUPER_ADMIN === $this->code;
    }

    /**
     * Remplace les droits du profil. Seuls les droits ATTRIBUABLES sont conservés :
     * un profil « normal » ne peut jamais contenir admins.manage (réservé au Super Admin).
     *
     * @param list<Permission> $permissions
     */
    public function setPermissions(array $permissions): void
    {
        $values = [];
        foreach ($permissions as $permission) {
            if ($this->isSuperAdminRole() || $permission->isAssignable()) {
                $values[$permission->value] = true;   // clé = valeur : déduplication gratuite
            }
        }
        $this->permissions = array_keys($values);
    }

    public function update(string $label, ?string $description, \DateTimeImmutable $now): void
    {
        $this->label = trim($label);
        $this->description = $description;
        $this->updatedAt = $now;
    }
}
