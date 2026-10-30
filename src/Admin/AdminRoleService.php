<?php

namespace App\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\AdminRole;
use App\Entity\User;
use App\Enum\Permission;
use App\Repository\AdminProfileRepository;
use App\Repository\AdminRoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Gestion des PROFILS d'administrateurs (Finance, Comptabilité, Support…) = ensembles de droits réutilisables.
 *
 * Réservé au Super Admin. Le profil « Super Admin » (système) n'est jamais modifiable ni supprimable, et aucun profil
 * ne peut contenir les droits admins.* (création/lecture/export des administrateurs) : ils restent au Super Admin.
 */
final class AdminRoleService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdminRoleRepository $roles,
        private readonly AdminProfileRepository $profiles,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Crée un profil (ou modifie celui dont l'identifiant est donné).
     *
     * @param list<Permission> $permissions
     *
     * @throws AdminActionException
     */
    public function save(User $actor, ?AdminRole $role, string $code, string $label, ?string $description, array $permissions): AdminRole
    {
        if (!$actor->isSuperAdmin() || !$actor->isActive()) {
            throw new AdminActionException('forbidden');
        }
        $label = trim($label);
        if (mb_strlen($label) < 2 || mb_strlen($label) > 100) {
            throw new AdminActionException('invalid_label');
        }
        $description = null !== $description && '' !== trim($description) ? mb_substr(trim($description), 0, 500) : null;
        // Un profil ne peut contenir que des droits attribuables (jamais admins.*).
        $permissions = array_values(array_filter($permissions, static fn (Permission $p): bool => $p->isAssignable()));
        $now = $this->clock->now();

        if (null === $role) {
            $code = mb_strtolower(trim($code));
            if (1 !== preg_match('/^[a-z][a-z0-9_]{1,49}$/', $code)) {
                throw new AdminActionException('invalid_code');
            }
            if (null !== $this->roles->findOneByCode($code)) {
                throw new AdminActionException('code_taken');
            }
            $role = new AdminRole($code, $label, $description, $permissions, false, $now);
            $this->em->persist($role);
            $action = 'created';
        } else {
            if ($role->isSystem()) {
                throw new AdminActionException('system_role');
            }
            $role->update($label, $description, $now);
            $role->setPermissions($permissions);
            $action = 'updated';
        }
        $this->em->flush();

        $this->audit->record(AuditAction::ADMIN_ROLE_SAVED, $actor, 'role', $role->getId()->toRfc4122(), [
            'operation' => $action,
            'code' => $role->getCode(),
            'permissions' => $role->getPermissions(),
        ]);

        return $role;
    }

    /**
     * Supprime un profil non système qu'aucun administrateur n'utilise.
     *
     * @throws AdminActionException
     */
    public function delete(User $actor, AdminRole $role): void
    {
        if (!$actor->isSuperAdmin() || !$actor->isActive()) {
            throw new AdminActionException('forbidden');
        }
        if ($role->isSystem()) {
            throw new AdminActionException('system_role');
        }
        if ($this->profiles->countByRole($role) > 0) {
            throw new AdminActionException('role_in_use');
        }

        $id = $role->getId()->toRfc4122();
        $code = $role->getCode();
        $this->em->remove($role);
        $this->em->flush();
        $this->audit->record(AuditAction::ADMIN_ROLE_SAVED, $actor, 'role', $id, ['operation' => 'deleted', 'code' => $code]);
    }
}
