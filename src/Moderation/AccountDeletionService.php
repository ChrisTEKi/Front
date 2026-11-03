<?php

namespace App\Moderation;

use App\Account\AccountPurger;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Enum\DeletionDelay;
use App\Notification\ModerationNotifier;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Suppression DÉFINITIVE d'un compte client ou fournisseur décidée par l'équipe (droit « accounts.delete »), avec le délai choisi
 * par l'administrateur : immédiat, 24 h, 48 h, 72 h, 7 j, 14 j, 30 j, 3 mois, 6 mois ou 1 an (DeletionDelay).
 *
 *  - Dès la décision, le compte est FERMÉ : connexion refusée (le titulaire voit un message clair, il ne peut pas « restaurer »
 *    comme après sa propre demande), sessions ouvertes invalidées, boutique masquée pour un fournisseur.
 *  - Au terme du délai, la commande `app:accounts:purge` (cron quotidien) efface le compte (voir AccountPurger : commandes
 *    anonymisées, journal épuré, produits et dossier supprimés). Une commande encore en cours REPORTE l'effacement.
 *  - « Immédiat » efface tout de suite, et n'est refusé que s'il reste des commandes en cours (rembourser d'abord, ou choisir un délai).
 *  - Un administrateur peut ANNULER à tout moment avant l'échéance : le compte retrouve son statut d'avant (actif, suspendu, banni).
 *  - Le titulaire est prévenu par e-mail ; la liste noire et le journal d'audit ne sont pas touchés.
 *
 * Les administrateurs ne se suppriment pas ici (Super Admin, onglet « Administrateurs »).
 */
final class AccountDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderRepository $orders,
        private readonly AccountPurger $purger,
        private readonly AuditLogger $audit,
        private readonly ModerationNotifier $notifier,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{purged: bool, scheduledAt: \DateTimeImmutable}
     *
     * @throws ModerationException « cannot_target_admin », « reason_required », « deletion_already_scheduled », « open_orders »
     */
    public function schedule(User $admin, User $target, DeletionDelay $delay, string $reason): array
    {
        if ($target->isAdmin()) {
            throw new ModerationException('cannot_target_admin');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ModerationException('reason_required');
        }
        if ($target->isDeletionByAdmin()) {
            throw new ModerationException('deletion_already_scheduled');
        }
        if ($delay->isImmediate() && ($open = $this->openOrders($target)) > 0) {
            throw new ModerationException('open_orders', ['%count%' => $open]);
        }

        $reason = mb_substr($reason, 0, 500);
        $now = $this->clock->now();
        $due = $delay->dueFrom($now);
        $id = $target->getId()->toRfc4122();
        $type = $target->getAccountType()->value;
        $email = $target->getEmail();

        $target->scheduleDeletionByAdmin($admin, $reason, $now, $due);
        $this->em->flush();
        $this->audit->record(AuditAction::DELETION_SCHEDULED_BY_ADMIN, $admin, 'user', $id, [
            'type' => $type, 'delay' => $delay->value, 'scheduled_at' => $due->format(\DATE_ATOM), 'reason' => $reason,
        ]);

        if ($delay->isImmediate()) {
            // Même règles que la purge quotidienne ; l'e-mail « compte supprimé » part de l'AccountPurger.
            if ($this->purger->purgeNow($id, $now)) {
                return ['purged' => true, 'scheduledAt' => $due];
            }
            // Course rare (une commande est apparue entre-temps) : le compte reste fermé et sera effacé par le prochain passage.
        }

        $this->notifier->notify($target, 'deletion_scheduled', ['%date%' => $due->format('d/m/Y'), '%detail%' => $reason]);

        return ['purged' => false, 'scheduledAt' => $due];
    }

    /** @throws ModerationException « cannot_target_admin », « no_deletion_scheduled » */
    public function cancel(User $admin, User $target): void
    {
        if ($target->isAdmin()) {
            throw new ModerationException('cannot_target_admin');
        }
        if (!$target->isDeletionByAdmin()) {
            throw new ModerationException('no_deletion_scheduled');
        }

        $target->cancelDeletionByAdmin($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::DELETION_CANCELLED_BY_ADMIN, $admin, 'user', $target->getId()->toRfc4122(), ['type' => $target->getAccountType()->value, 'status' => $target->getStatus()->value]);
        $this->notifier->notify($target, 'deletion_cancelled');
    }

    /** Commandes en cours qui dépendent de ce compte (les siennes pour un client, celles qui contiennent ses articles pour un fournisseur). */
    private function openOrders(User $target): int
    {
        return $target->isSupplier() ? $this->orders->countOpenForSupplier($target) : $this->orders->countOpenForClient($target);
    }
}
