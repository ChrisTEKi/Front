<?php

namespace App\Moderation;

use App\Entity\AuditLog;
use App\Entity\SupplierAppeal;
use App\Entity\SupplierAppealDocument;
use App\Entity\User;
use App\Enum\ProductStatus;
use App\Phone\PhoneNumber;
use App\Repository\AuditLogRepository;
use App\Repository\OrderItemRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\SupplierAppealRepository;
use App\Repository\SupplierDocumentRepository;
use App\Repository\SupplierProfileRepository;

/**
 * Données de la FICHE D'UN COMPTE (client ou fournisseur) dans l'espace administrateur : identité, état, dossier fournisseur,
 * contestations, catalogue, commandes, historique des décisions. Lecture seule : les actions passent par les services et l'API.
 */
final class AccountOverview
{
    public function __construct(
        private readonly SupplierProfileRepository $profiles,
        private readonly SupplierAppealRepository $appeals,
        private readonly ProductRepository $products,
        private readonly OrderRepository $orders,
        private readonly OrderItemRepository $orderItems,
        private readonly AuditLogRepository $audit,
        private readonly SupplierDocumentRepository $supplierDocuments,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(User $target): array
    {
        $id = $target->getId()->toRfc4122();
        $data = [
            'id' => $id,
            'name' => $target->getFullName(),
            'email' => $target->getEmail(),
            'phone' => null === $target->getPhone() ? null : PhoneNumber::fromE164($target->getPhone())->masked(),
            'type' => $target->getAccountType()->value,
            'status' => $target->getStatus()->value,
            'emailVerified' => $target->isEmailVerified(),
            'phoneVerified' => $target->isPhoneVerified(),
            'twoFactor' => $target->isTwoFactorEnabled(),
            'createdAt' => $target->getCreatedAt(),
            'lastLoginAt' => $target->getLastLoginAt(),
            'ban' => $target->isBanned() ? ['at' => $target->getBannedAt(), 'reason' => $target->getBanReason(), 'by' => $target->getBannedBy()?->getFullName()] : null,
            'frozen' => $target->isFundsFrozen() ? ['at' => $target->getFundsFrozenAt(), 'reason' => $target->getFundsFrozenReason(), 'by' => $target->getFundsFrozenBy()?->getFullName()] : null,
            'deletion' => $target->isPendingDeletion() ? [
                'byAdmin' => $target->isDeletionByAdmin(),
                'at' => $target->getDeletionScheduledAt(),
                'reason' => $target->getDeletionReason(),
                'by' => $target->getDeletionRequestedBy()?->getFullName(),
            ] : null,
            'refundable' => null,
            'supplier' => null,
            'appeals' => [],
            'products' => null,
            'orders' => null,
            'history' => array_map(static fn (AuditLog $log): array => [
                'at' => $log->getCreatedAt(),
                'actor' => $log->getActorEmail(),
                'action' => $log->getAction(),
                'details' => [] === $log->getMetadata() ? '' : (string) json_encode($log->getMetadata(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            ], $this->audit->findForTarget($id, 30)),
        ];

        if ($target->isSupplier()) {
            $profile = $this->profiles->findOneByUser($target);
            $data['supplier'] = null === $profile ? null : [
                'company' => $profile->getCompanyName(),
                'address' => $profile->getCompanyAddress(),
                'status' => $profile->getStatus()->value,
                'reason' => $profile->getRejectionReason(),
                'reviewedAt' => $profile->getReviewedAt(),
                'submittedAt' => $profile->getSubmittedAt(),
                // Pièces déposées à l'inscription (identité, NIF / STAT) : nature => {id, name, type, size} ; vide pour un ancien dossier.
                'documents' => $this->supplierDocuments->summariesFor([$id])[$id] ?? [],
            ];
            $data['appeals'] = array_map(fn (SupplierAppeal $a): array => [
                'id' => $a->getId()->toRfc4122(),
                'status' => $a->getStatus()->value,
                'open' => $a->isOpen(),
                'contested' => $a->getContestedStatus(),
                'message' => $a->getMessage(),
                'createdAt' => $a->getCreatedAt(),
                'decidedAt' => $a->getDecidedAt(),
                'decidedBy' => $a->getDecidedBy()?->getFullName(),
                'note' => $a->getDecisionNote(),
                'documents' => array_map(static fn (SupplierAppealDocument $d): array => [
                    'id' => $d->getId()->toRfc4122(),
                    'name' => $d->getFileName(),
                    'size' => $d->getSize(),
                ], $a->getDocuments()->toArray()),
            ], $this->appeals->findForSupplier($target));
            $data['products'] = [
                'total' => $this->products->countByOwner($target),
                'removed' => $this->products->countByOwnerAndStatus($target, ProductStatus::Removed),
            ];
            $data['orders'] = $this->orderItems->statsForSupplier($id);
            $data['refundable'] = [
                'open' => $this->orders->countRefundableForSupplier($target, false),
                'all' => $this->orders->countRefundableForSupplier($target, true),
            ];
        } elseif ($target->isClient()) {
            $data['orders'] = ['total' => $this->orders->countForClient($target), 'open' => $this->orders->countOpenForClient($target)];
        }

        return $data;
    }
}
