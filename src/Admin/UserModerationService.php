<?php

namespace App\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Suspension / réactivation d'un client ou d'un fournisseur (droit « users.suspend »).
 *
 * Suspendre coupe l'accès IMMÉDIATEMENT : la session en cours est invalidée à la requête suivante (User::isEqualTo
 * compare le statut) et la connexion est refusée (UserChecker). Les administrateurs ne se gèrent pas ici
 * (voir AdminAccountService, réservé au Super Admin).
 */
final class UserModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @throws AdminActionException */
    public function suspend(User $admin, User $target): void
    {
        $this->assertManageable($admin, $target);
        // Un compte en cours de suppression garde son statut : sinon la purge programmée ne se déclencherait plus.
        if (!$target->isActive()) {
            throw new AdminActionException('invalid_transition');
        }

        $target->suspend();
        $target->touch($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::USER_SUSPENDED, $admin, 'user', $target->getId()->toRfc4122(), ['type' => $target->getAccountType()->value]);
    }

    /** @throws AdminActionException */
    public function reactivate(User $admin, User $target): void
    {
        $this->assertManageable($admin, $target);
        // Seul un compte SUSPENDU se réactive ici (réactiver un compte en suppression annulerait la demande du client).
        if (!$target->isSuspended()) {
            throw new AdminActionException('invalid_transition');
        }

        $target->reactivate();
        $target->touch($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::USER_REACTIVATED, $admin, 'user', $target->getId()->toRfc4122(), ['type' => $target->getAccountType()->value]);
    }

    private function assertManageable(User $admin, User $target): void
    {
        if ($target->isAdmin()) {
            throw new AdminActionException('use_admin_management');
        }
        if ($admin->getId()->equals($target->getId())) {
            throw new AdminActionException('cannot_suspend_self');
        }
    }
}
