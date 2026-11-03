<?php

namespace App\Catalog;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\Product;
use App\Entity\User;
use App\Notification\ModerationNotifier;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Pouvoirs des administrateurs sur le catalogue : retirer / rétablir, signaler, mettre en avant, supprimer — produit par
 * produit ou pour tout le catalogue d'un fournisseur. Les DROITS (products.remove, products.flag…) sont vérifiés par le
 * contrôleur ; ce service applique les règles métier et journalise chaque décision.
 *
 *  - Retirer : le produit disparaît de la boutique, son motif est conservé et le fournisseur est prévenu par e-mail.
 *    Le fournisseur ne peut plus le réactiver : seul un administrateur le rétablit.
 *  - Signaler : simple marque « à examiner » (visible des administrateurs seulement), sans effet sur la vente.
 *  - Mettre en avant : le produit passe en tête de la boutique (pour N jours, ou jusqu'à décision contraire).
 *  - Supprimer : effacement définitif (irréversible) ; les commandes passées gardent leur copie des lignes.
 */
final class ProductModerationService
{
    /** Durée maximale d'une mise en avant à durée limitée. */
    public const MAX_FEATURE_DAYS = 365;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $products,
        private readonly AuditLogger $audit,
        private readonly ModerationNotifier $notifier,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @throws CatalogException */
    public function remove(User $admin, Product $product, string $reason): void
    {
        $reason = $this->reason($reason);
        if ($product->isRemoved()) {
            throw new CatalogException('already_removed');
        }

        $product->remove($admin, $reason, $this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_REMOVED, $admin, 'product', $product->getId()->toRfc4122(), ['reason' => $reason, 'name' => $product->getName()]);
        if (null !== $owner = $product->getOwner()) {
            $this->notifier->notify($owner, 'product_removed', ['%detail%' => $reason, '%product%' => $product->getName()]);
        }
    }

    /** @throws CatalogException */
    public function restore(User $admin, Product $product): void
    {
        if (!$product->isRemoved()) {
            throw new CatalogException('not_removed');
        }

        $product->restore($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_RESTORED, $admin, 'product', $product->getId()->toRfc4122(), ['name' => $product->getName()]);
        if (null !== $owner = $product->getOwner()) {
            $this->notifier->notify($owner, 'product_restored', ['%product%' => $product->getName()]);
        }
    }

    /** @throws CatalogException */
    public function flag(User $admin, Product $product, string $reason): void
    {
        $reason = $this->reason($reason);

        $product->flag($admin, $reason, $this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_FLAGGED, $admin, 'product', $product->getId()->toRfc4122(), ['reason' => $reason]);
    }

    public function unflag(User $admin, Product $product): void
    {
        $product->unflag($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_UNFLAGGED, $admin, 'product', $product->getId()->toRfc4122());
    }

    /**
     * @param int|null $days durée en jours (1 à 365) ; null = jusqu'à décision contraire
     *
     * @throws CatalogException « invalid_days », « product_not_active »
     */
    public function feature(User $admin, Product $product, ?int $days): void
    {
        if (null !== $days && ($days < 1 || $days > self::MAX_FEATURE_DAYS)) {
            throw new CatalogException('invalid_days', ['%max%' => self::MAX_FEATURE_DAYS]);
        }
        // Mettre en avant un produit retiré ou masqué n'aurait aucun effet visible : on le refuse plutôt que de l'ignorer.
        if (!$product->isActive()) {
            throw new CatalogException('product_not_active');
        }

        $now = $this->clock->now();
        $product->feature($admin, null === $days ? null : $now->modify('+'.$days.' days'), $now);
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_FEATURED, $admin, 'product', $product->getId()->toRfc4122(), ['days' => $days]);
    }

    public function unfeature(User $admin, Product $product): void
    {
        $product->unfeature($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_UNFEATURED, $admin, 'product', $product->getId()->toRfc4122());
    }

    /** Suppression définitive d'un produit (irréversible). */
    public function delete(User $admin, Product $product): void
    {
        $id = $product->getId()->toRfc4122();
        $name = $product->getName();
        $owner = $product->getOwner();

        $this->em->remove($product);
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_DELETED, $admin, 'product', $id, ['name' => $name, 'by_admin' => true]);
        if (null !== $owner) {
            $this->notifier->notify($owner, 'product_deleted', ['%product%' => $name]);
        }
    }

    /**
     * Retire de la vente TOUT le catalogue d'un fournisseur (réversible produit par produit). Retourne le nombre de produits retirés.
     *
     * @throws CatalogException
     */
    public function removeCatalog(User $admin, User $supplier, string $reason, bool $notify = true): int
    {
        $reason = $this->reason($reason);
        $count = $this->products->removeAllOf($supplier, $admin, $reason, $this->clock->now());
        // NB : la requête de masse ne passe pas par le gestionnaire d'entités ; on ne le vide PAS (l'administrateur et le
        // fournisseur déjà chargés serviraient ensuite de « détachés » au journal d'audit). L'appelant relit si besoin.
        $this->audit->record(AuditAction::CATALOG_REMOVED, $admin, 'user', $supplier->getId()->toRfc4122(), ['count' => $count, 'reason' => $reason]);
        if ($notify && $count > 0) {
            $this->notifier->notify($supplier, 'catalog_removed', ['%count%' => $count, '%detail%' => $reason]);
        }

        return $count;
    }

    /** Supprime DÉFINITIVEMENT tout le catalogue d'un fournisseur. Retourne le nombre de produits supprimés. */
    public function deleteCatalog(User $admin, User $supplier): int
    {
        $count = $this->products->deleteAllOf($supplier);

        $this->audit->record(AuditAction::CATALOG_DELETED, $admin, 'user', $supplier->getId()->toRfc4122(), ['count' => $count]);

        return $count;
    }

    /** Motif obligatoire (5 à 500 caractères) : conservé dans le journal et montré au fournisseur. */
    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new CatalogException('reason_required');
        }

        return mb_substr($reason, 0, 500);
    }
}
