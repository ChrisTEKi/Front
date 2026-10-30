<?php

namespace App\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\SupplierStatus;
use App\Notification\SecurityNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Décision d'un administrateur sur un dossier fournisseur (droit « suppliers.approve »).
 *
 * Transitions autorisées :
 *   en attente → approuvé | refusé         approuvé → suspendu         suspendu → approuvé
 * Un dossier refusé reste refusé : le fournisseur dépose une nouvelle demande (pas de retour en arrière silencieux).
 *
 * Un fournisseur n'est approuvable que si son e-mail ET son téléphone sont vérifiés (règle métier). Chaque décision est
 * journalisée et notifiée par e-mail au fournisseur (dans sa langue).
 */
final class SupplierReviewService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly SecurityNotifier $notifier,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @throws AdminActionException */
    public function approve(User $admin, SupplierProfile $profile): void
    {
        $supplier = $profile->getUser();
        if (!\in_array($profile->getStatus(), [SupplierStatus::Pending, SupplierStatus::Suspended], true)) {
            throw new AdminActionException('invalid_transition');
        }
        if (!$supplier->isEmailVerified() || !$supplier->isPhoneVerified()) {
            throw new AdminActionException('supplier_not_verified');
        }

        $profile->approve($admin, $this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::SUPPLIER_APPROVED, $admin, 'user', $supplier->getId()->toRfc4122(), ['company' => $profile->getCompanyName()]);
        $this->notifier->supplierDecision($supplier, true);
    }

    /** @throws AdminActionException */
    public function reject(User $admin, SupplierProfile $profile, string $reason): void
    {
        if (SupplierStatus::Pending !== $profile->getStatus()) {
            throw new AdminActionException('invalid_transition');
        }
        $reason = $this->cleanReason($reason);

        $profile->reject($admin, $reason, $this->clock->now());
        $this->em->flush();
        $supplier = $profile->getUser();
        $this->audit->record(AuditAction::SUPPLIER_REJECTED, $admin, 'user', $supplier->getId()->toRfc4122(), ['company' => $profile->getCompanyName(), 'reason' => $reason]);
        $this->notifier->supplierDecision($supplier, false, $reason);
    }

    /** @throws AdminActionException */
    public function suspend(User $admin, SupplierProfile $profile, string $reason): void
    {
        if (SupplierStatus::Approved !== $profile->getStatus()) {
            throw new AdminActionException('invalid_transition');
        }
        $reason = $this->cleanReason($reason);

        $profile->suspend($admin, $reason, $this->clock->now());
        $this->em->flush();
        $supplier = $profile->getUser();
        $this->audit->record(AuditAction::SUPPLIER_REJECTED, $admin, 'user', $supplier->getId()->toRfc4122(), ['company' => $profile->getCompanyName(), 'reason' => $reason, 'operation' => 'suspended']);
    }

    /** Un motif est obligatoire (5 à 500 caractères) : il est montré au fournisseur et conservé dans le journal. */
    private function cleanReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new AdminActionException('reason_required');
        }

        return mb_substr($reason, 0, 500);
    }
}
