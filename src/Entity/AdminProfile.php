<?php

namespace App\Entity;

use App\Enum\Permission;
use App\Repository\AdminProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Données propres à un compte administrateur — table `admin_profiles` (1 ligne par admin).
 *
 * Droits EFFECTIFS d'un admin =
 *     droits de son profil (AdminRole)
 *   + droits ajoutés individuellement (`extra_permissions`)
 *   − droits retirés individuellement (`denied_permissions` = « restrictions »)
 * Le retrait l'emporte toujours sur l'ajout. Le Super Admin a tout (voir PermissionVoter).
 */
#[ORM\Entity(repositoryClass: AdminProfileRepository::class)]
#[ORM\Table(name: 'admin_profiles', options: ['comment' => 'Données d\'un compte administrateur : profil de droits + droits ajoutés/retirés individuellement.'])]
class AdminProfile
{
    /** Le compte utilisateur (clé primaire ET clé étrangère). */
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Profil de l'admin. RESTRICT : on ne peut pas supprimer un profil encore utilisé. */
    #[ORM\ManyToOne(targetEntity: AdminRole::class)]
    #[ORM\JoinColumn(name: 'admin_role_id', nullable: false, onDelete: 'RESTRICT')]
    private AdminRole $role;

    /** @var list<string> Droits ajoutés en plus du profil. */
    #[ORM\Column(name: 'extra_permissions', type: Types::JSON, options: ['comment' => 'Droits ajoutés individuellement en plus du profil', 'jsonb' => true])]
    private array $extraPermissions = [];

    /** @var list<string> Droits retirés malgré le profil (restrictions). */
    #[ORM\Column(name: 'denied_permissions', type: Types::JSON, options: ['comment' => 'Droits retirés individuellement (restrictions) : l\'emportent sur le profil', 'jsonb' => true])]
    private array $deniedPermissions = [];

    /** Le Super Admin qui a créé ce compte (NULL si ce dernier a été supprimé, ou pour le tout premier compte créé en CLI). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<Permission> $extra
     * @param list<Permission> $denied
     */
    public function __construct(User $user, AdminRole $role, array $extra, array $denied, ?User $createdBy, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->role = $role;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->setOverrides($extra, $denied, $now);
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): AdminRole
    {
        return $this->role;
    }

    /** @return list<string> */
    public function getExtraPermissions(): array
    {
        return $this->extraPermissions;
    }

    /** @return list<string> */
    public function getDeniedPermissions(): array
    {
        return $this->deniedPermissions;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function changeRole(AdminRole $role, \DateTimeImmutable $now): void
    {
        $this->role = $role;
        $this->updatedAt = $now;
    }

    /**
     * Remplace les ajouts/retraits individuels (seuls les droits attribuables sont retenus).
     *
     * @param list<Permission> $extra
     * @param list<Permission> $denied
     */
    public function setOverrides(array $extra, array $denied, \DateTimeImmutable $now): void
    {
        $this->extraPermissions = self::normalize($extra);
        $this->deniedPermissions = self::normalize($denied);
        $this->updatedAt = $now;
    }

    /**
     * Calcule la liste finale des droits de l'admin.
     *
     * @return list<string> valeurs de l'enum Permission
     */
    public function effectivePermissions(): array
    {
        // Super Admin : tous les droits, sans condition.
        if ($this->user->isSuperAdmin()) {
            return Permission::values();
        }
        // 1) droits du profil + ajouts individuels
        $granted = array_unique([...$this->role->getPermissions(), ...$this->extraPermissions]);
        // 2) on retire les restrictions
        $effective = array_diff($granted, $this->deniedPermissions);
        // 3) filet de sécurité : jamais de droit réservé au Super Admin pour un admin normal
        $effective = array_filter($effective, static fn (string $value) => ($p = Permission::tryFrom($value)) && $p->isAssignable());

        return array_values($effective);
    }

    public function hasPermission(Permission $permission): bool
    {
        return \in_array($permission->value, $this->effectivePermissions(), true);
    }

    /**
     * @param list<Permission> $permissions
     *
     * @return list<string>
     */
    private static function normalize(array $permissions): array
    {
        $values = [];
        foreach ($permissions as $permission) {
            if ($permission->isAssignable()) {
                $values[$permission->value] = true;
            }
        }

        return array_keys($values);
    }
}
