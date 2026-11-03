<?php

namespace App\Catalog;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\SupplierStatus;
use App\Repository\ProductRepository;
use App\Repository\SupplierProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Gestion du catalogue par SON PROPRIÉTAIRE : un fournisseur pour ses produits, un administrateur pour ceux de GoBuy.
 *
 * Règles :
 *  - un fournisseur gère son catalogue tant que son dossier est « en attente » ou « approuvé » (il peut préparer ses produits
 *    avant la validation, mais ils ne sont visibles qu'une fois le dossier approuvé : voir ProductRepository::findStorefront) ;
 *  - un dossier refusé ou suspendu ne peut plus rien créer ni modifier ;
 *  - un produit retiré par un administrateur ne peut être ni modifié ni réactivé par son propriétaire ;
 *  - 2 000 produits au plus par fournisseur.
 *
 * Les droits des administrateurs (retirer, signaler, mettre en avant…) sont dans ProductModerationService.
 */
final class ProductService
{
    public const MAX_PER_SUPPLIER = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $products,
        private readonly SupplierProfileRepository $profiles,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Le fournisseur a-t-il le droit de gérer son catalogue ? (dossier en attente ou approuvé, compte actif)
     *
     * @throws CatalogException « supplier_not_active »
     */
    public function assertSupplierCanManage(User $supplier): void
    {
        $profile = $this->profiles->findOneByUser($supplier);
        $allowed = null !== $profile
            && $supplier->isSupplier()
            && $supplier->isActive()
            && \in_array($profile->getStatus(), [SupplierStatus::Pending, SupplierStatus::Approved], true);
        if (!$allowed) {
            throw new CatalogException('supplier_not_active');
        }
    }

    /**
     * Crée un produit. $owner = le fournisseur, ou null pour un produit GoBuy (alors $actor est l'administrateur).
     *
     * @throws CatalogException
     */
    public function create(?User $owner, ProductData $data, User $actor): Product
    {
        if (null !== $owner) {
            $this->assertSupplierCanManage($owner);
            if ($this->products->countByOwner($owner) >= self::MAX_PER_SUPPLIER) {
                throw new CatalogException('product_limit', ['%max%' => self::MAX_PER_SUPPLIER]);
            }
        }

        $product = new Product(
            $owner,
            $data->name,
            $data->priceMgaInt(),
            $this->clock->now(),
            '' !== $data->description ? $data->description : null,
            '' !== $data->category ? $data->category : null,
            '' !== $data->reference ? $data->reference : null,
            $data->overridesMinor(),
        );
        $this->em->persist($product);
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_CREATED, $actor, 'product', $product->getId()->toRfc4122(), ['owner' => $owner?->getId()->toRfc4122() ?? 'gobuy']);

        return $product;
    }

    /** @throws CatalogException */
    public function update(Product $product, ProductData $data, User $actor): void
    {
        $this->assertEditable($product);

        $product->update(
            $data->name,
            $data->priceMgaInt(),
            '' !== $data->description ? $data->description : null,
            '' !== $data->category ? $data->category : null,
            '' !== $data->reference ? $data->reference : null,
            $data->overridesMinor(),
            $this->clock->now(),
        );
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_UPDATED, $actor, 'product', $product->getId()->toRfc4122());
    }

    /** Masque ou réaffiche un produit. @throws CatalogException « product_removed » si un administrateur l'a retiré */
    public function setVisible(Product $product, bool $visible, User $actor): void
    {
        $this->assertEditable($product);

        $product->setVisible($visible, $this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_VISIBILITY, $actor, 'product', $product->getId()->toRfc4122(), ['visible' => $visible]);
    }

    /** Suppression définitive par le propriétaire. Un produit retiré par un administrateur reste (preuve de la modération). */
    public function delete(Product $product, User $actor): void
    {
        $this->assertEditable($product);

        $id = $product->getId()->toRfc4122();
        $this->em->remove($product);
        $this->em->flush();
        $this->audit->record(AuditAction::PRODUCT_DELETED, $actor, 'product', $id);
    }

    /** @throws CatalogException */
    private function assertEditable(Product $product): void
    {
        if ($product->isRemoved()) {
            throw new CatalogException('product_removed');
        }
        if (null !== $product->getOwner()) {
            // Un fournisseur dont le dossier a été refusé/suspendu ne modifie plus rien.
            $this->assertSupplierCanManage($product->getOwner());
        }
    }
}
