<?php

namespace App\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\SupplierStatus;
use App\Notification\ModerationNotifier;
use App\Notification\SecurityNotifier;
use App\Repository\SupplierAppealRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Décision d'un administrateur sur un dossier fournisseur (droit « suppliers.approve »).
 *
 * Transitions autorisées :
 *   en attente → approuvé | refusé
 *   approuvé   → suspendu | refusé
 *   suspendu   → approuvé | refusé
 *   refusé     → approuvé            (RE-VALIDATION, par exemple après une contestation ou des pièces complémentaires)
 * Un dossier refusé n'est donc plus « définitif » : l'équipe peut revenir sur sa décision, et chaque retour en arrière est
 * journalisé et notifié au fournisseur.
 *
 * Un fournisseur n'est approuvable que si son e-mail ET son téléphone sont vérifiés (règle métier). Chaque décision est
 * journalisée et notifiée par e-mail au fournisseur (dans sa langue). Approuver un dossier ferme aussi toute contestation
 * encore ouverte de ce fournisseur (acceptée : le but de la contestation est atteint).
 *
 * Effet sur la boutique : un fournisseur dont le dossier n'est plus « approuvé » disparaît de la vente (règle unique
 * « vendable », voir ProductRepository) ; ses produits ne sont pas touchés et reviennent à la re-validation.
 */
final class SupplierReviewService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly SecurityNotifier $notifier,
        private readonly ModerationNotifier $moderationNotifier,
        private readonly SupplierAppealRepository $appeals,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string|null $note message de l'équipe, joint à l'e-mail quand le dossier est re-validé (ex. suite à une contestation)
     *
     * @throws AdminActionException
     */
    public function approve(User $admin, SupplierProfile $profile, ?string $note = null): void
    {
        $supplier = $profile->getUser();
        $from = $profile->getStatus();
        if (!\in_array($from, [SupplierStatus::Pending, SupplierStatus::Suspended, SupplierStatus::Rejected], true)) {
            throw new AdminActionException('invalid_transition');
        }
        if (!$supplier->isEmailVerified() || !$supplier->isPhoneVerified()) {
            throw new AdminActionException('supplier_not_verified');
        }
        if (!$supplier->isActive()) {
            // Un compte suspendu, banni ou en cours de suppression ne se « re-valide » pas : d'abord rétablir le compte.
            throw new AdminActionException('account_not_active');
        }

        $now = $this->clock->now();
        $profile->approve($admin, $now);
        // Toute contestation ouverte est réputée acceptée : le fournisseur a obtenu ce qu'il demandait.
        $closed = $this->appeals->findOpenForSupplier($supplier);
        foreach ($closed as $appeal) {
            $appeal->accept($admin, $note, $now);
        }
        $this->em->flush();

        $this->audit->record(AuditAction::SUPPLIER_APPROVED, $admin, 'user', $supplier->getId()->toRfc4122(), [
            'company' => $profile->getCompanyName(),
            'from' => $from->value,
            'operation' => SupplierStatus::Pending === $from ? 'approved' : 'revalidated',
        ]);
        foreach ($closed as $appeal) {
            $this->audit->record(AuditAction::APPEAL_ACCEPTED, $admin, 'user', $supplier->getId()->toRfc4122(), ['appeal' => $appeal->getId()->toRfc4122()]);
        }

        if (SupplierStatus::Pending === $from) {
            $this->notifier->supplierDecision($supplier, true);
        } else {
            // Avec un message de l'équipe (ex. réponse à une contestation) : e-mail qui le reprend ; sinon e-mail simple.
            $note = null !== $note && '' !== trim($note) ? trim($note) : null;
            $this->moderationNotifier->notify($supplier, null === $note ? 'supplier_revalidated' : 'supplier_revalidated_note', ['%company%' => $profile->getCompanyName(), '%detail%' => (string) $note]);
        }
    }

    /** @throws AdminActionException */
    public function reject(User $admin, SupplierProfile $profile, string $reason): void
    {
        $from = $profile->getStatus();
        if (!\in_array($from, [SupplierStatus::Pending, SupplierStatus::Approved, SupplierStatus::Suspended], true)) {
            throw new AdminActionException('invalid_transition');
        }
        $reason = $this->cleanReason($reason);

        $profile->reject($admin, $reason, $this->clock->now());
        $this->em->flush();
        $supplier = $profile->getUser();
        $this->audit->record(AuditAction::SUPPLIER_REJECTED, $admin, 'user', $supplier->getId()->toRfc4122(), ['company' => $profile->getCompanyName(), 'reason' => $reason, 'from' => $from->value]);
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
        $this->audit->record(AuditAction::SUPPLIER_SUSPENDED, $admin, 'user', $supplier->getId()->toRfc4122(), ['company' => $profile->getCompanyName(), 'reason' => $reason]);
        // Le fournisseur est prévenu (comme pour un refus) : sa boutique n'est plus visible, il peut contester la décision.
        $this->moderationNotifier->notify($supplier, 'supplier_suspended', ['%company%' => $profile->getCompanyName(), '%detail%' => $reason]);
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
