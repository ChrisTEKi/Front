<?php

namespace App\Moderation;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Catalog\ProductModerationService;
use App\Entity\User;
use App\Notification\ModerationNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Sanctions sur un compte CLIENT ou FOURNISSEUR (jamais un administrateur : ils se gèrent dans l'onglet réservé au Super Admin).
 *
 *  - BANNIR (définitif) : clôture immédiate — connexion refusée et sessions ouvertes invalidées à la requête suivante (le statut
 *    change, voir User::isEqualTo). Options : retirer ou supprimer tout le catalogue, rembourser les acheteurs lésés, inscrire
 *    les identifiants sur la liste noire. Le compte reste en base : son e-mail et son téléphone restent occupés (aucune
 *    réinscription avec). SEUL le Super Admin peut lever un bannissement.
 *  - GELER les fonds (conservatoire) : un fournisseur ne vend plus (ses produits quittent la boutique) et ne reçoit aucun
 *    versement (PayoutGuard) ; un client ne peut plus commander. Le compte reste connectable (il peut s'expliquer). Réversible.
 *
 * Les DROITS de l'administrateur (sanctions.ban, sanctions.freeze…) et la confirmation par mot de passe sont contrôlés en amont
 * par AdminSanctionController ; ce service applique les règles métier, journalise et prévient le titulaire par e-mail.
 */
final class SanctionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly ModerationNotifier $notifier,
        private readonly ProductModerationService $catalog,
        private readonly RefundService $refunds,
        private readonly BlacklistService $blacklist,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{catalog: int, refund: array{orders: int, buyers: int, amount: int, remaining: int}|null, blacklist: list<string>}
     *
     * @throws ModerationException
     */
    public function ban(User $admin, User $target, string $reason, BanOptions $options): array
    {
        $this->assertTargetable($target);
        $options->validate();
        $reason = $this->reason($reason);
        if ($target->isBanned()) {
            throw new ModerationException('already_banned');
        }
        if ('none' !== $options->catalog && !$target->isSupplier()) {
            throw new ModerationException('not_a_supplier');
        }

        $target->ban($admin, $reason, $this->clock->now());
        $this->em->flush();

        // Les mesures d'accompagnement : chacune est indépendante, le bannissement est déjà prononcé quoi qu'il arrive.
        $products = match ($options->catalog) {
            'remove' => $this->catalog->removeCatalog($admin, $target, $reason, false),
            'delete' => $this->catalog->deleteCatalog($admin, $target),
            default => 0,
        };

        $refund = null;
        if ('none' !== $options->refund) {
            $report = $target->isSupplier()
                ? $this->refundSupplier($admin, $target, $options->refund, $reason)
                : $this->refunds->refundClientOpenOrders($admin, $target, $reason);
            $refund = $report->toArray();
        }

        $listed = [] === $options->blacklist ? [] : $this->blacklist->addForAccount($admin, $target, $options->blacklist, $reason, $options->blacklistDays);

        $this->audit->record(AuditAction::ACCOUNT_BANNED, $admin, 'user', $target->getId()->toRfc4122(), [
            'type' => $target->getAccountType()->value,
            'reason' => $reason,
            'catalog' => $options->catalog,
            'products' => $products,
            'refund' => $options->refund,
            'refunded_orders' => $refund['orders'] ?? 0,
            'blacklist' => $listed,
        ]);
        $this->notifier->notify($target, 'account_banned', ['%detail%' => $reason]);

        return ['catalog' => $products, 'refund' => $refund, 'blacklist' => $listed];
    }

    /**
     * Lève un bannissement : le compte redevient actif. Les produits supprimés ne reviennent pas, les produits retirés restent
     * retirés (à rétablir un par un) et les remboursements ne sont pas annulés. La liste noire se gère à part.
     *
     * @throws ModerationException « only_super_admin », « not_banned », « reason_required »
     */
    public function unban(User $admin, User $target, string $note): void
    {
        if (!$admin->isSuperAdmin()) {
            throw new ModerationException('only_super_admin');
        }
        $this->assertTargetable($target);
        $note = $this->reason($note);
        if (!$target->isBanned()) {
            throw new ModerationException('not_banned');
        }

        $target->unban($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::ACCOUNT_UNBANNED, $admin, 'user', $target->getId()->toRfc4122(), ['type' => $target->getAccountType()->value, 'note' => $note]);
        $this->notifier->notify($target, 'account_unbanned', ['%detail%' => $note]);
    }

    /** @throws ModerationException « already_frozen », « reason_required » */
    public function freeze(User $admin, User $target, string $reason): void
    {
        $this->assertTargetable($target);
        $reason = $this->reason($reason);
        if ($target->isFundsFrozen()) {
            throw new ModerationException('already_frozen');
        }

        $target->freezeFunds($admin, $reason, $this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::FUNDS_FROZEN, $admin, 'user', $target->getId()->toRfc4122(), ['type' => $target->getAccountType()->value, 'reason' => $reason]);
        $this->notifier->notify($target, 'funds_frozen', ['%detail%' => $reason]);
    }

    /** @throws ModerationException « not_frozen » */
    public function unfreeze(User $admin, User $target): void
    {
        $this->assertTargetable($target);
        if (!$target->isFundsFrozen()) {
            throw new ModerationException('not_frozen');
        }

        $target->unfreezeFunds($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::FUNDS_UNFROZEN, $admin, 'user', $target->getId()->toRfc4122(), ['type' => $target->getAccountType()->value]);
        $this->notifier->notify($target, 'funds_unfrozen');
    }

    /** Un fournisseur sans acheteur à rembourser n'est pas une erreur au moment du bannissement : le rapport est simplement vide. */
    private function refundSupplier(User $admin, User $supplier, string $scope, string $reason): RefundReport
    {
        try {
            return $this->refunds->refundSupplierBuyers($admin, $supplier, $scope, $reason);
        } catch (ModerationException $e) {
            if ('nothing_to_refund' === $e->reason) {
                return new RefundReport();
            }

            throw $e;
        }
    }

    private function assertTargetable(User $target): void
    {
        if ($target->isAdmin()) {
            throw new ModerationException('cannot_target_admin');
        }
    }

    /** Motif obligatoire (5 à 500 caractères) : conservé au journal et communiqué au titulaire. */
    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ModerationException('reason_required');
        }

        return mb_substr($reason, 0, 500);
    }
}
