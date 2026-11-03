<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ProductStatus;
use App\Enum\SupplierStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** Produit par identifiant texte (UUID), ou null si l'identifiant est invalide ou inconnu. */
    public function findOneByUuid(string $id): ?Product
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->find(Uuid::fromString($id));
    }

    /** Produit d'un fournisseur donné : un identifiant appartenant à quelqu'un d'autre donne « introuvable ». */
    public function findOwnedBy(User $owner, string $id): ?Product
    {
        $product = $this->findOneByUuid($id);

        return null !== $product && $product->getOwner()?->getId()->equals($owner->getId()) ? $product : null;
    }

    /**
     * Tous les produits d'un fournisseur, les plus récents d'abord.
     *
     * @return list<Product>
     */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.owner = :owner')->setParameter('owner', $owner)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    public function countByOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')
            ->andWhere('p.owner = :owner')->setParameter('owner', $owner)
            ->getQuery()->getSingleScalarResult();
    }

    public function countByOwnerAndStatus(User $owner, ProductStatus $status): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')
            ->andWhere('p.owner = :owner')->setParameter('owner', $owner)
            ->andWhere('p.status = :status')->setParameter('status', $status)
            ->getQuery()->getSingleScalarResult();
    }

    // ------------------------------------------------------------------ Recherche d'administration

    /**
     * Liste filtrée pour l'administration.
     *
     * @param array{owner?: string, status?: string, flagged?: bool, featured?: bool, q?: string} $filters
     *        owner : « all » | « gobuy » | UUID d'un fournisseur
     *
     * @return list<Product>
     */
    public function search(array $filters, int $page, int $perPage, \DateTimeImmutable $now): array
    {
        return $this->searchQuery($filters, $now)
            ->select('p')->addSelect('o')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()->getResult();
    }

    /** @param array{owner?: string, status?: string, flagged?: bool, featured?: bool, q?: string} $filters */
    public function countSearch(array $filters, \DateTimeImmutable $now): int
    {
        return (int) $this->searchQuery($filters, $now)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
    }

    /** @param array{owner?: string, status?: string, flagged?: bool, featured?: bool, q?: string} $filters */
    private function searchQuery(array $filters, \DateTimeImmutable $now): QueryBuilder
    {
        // Le dossier fournisseur est joint pour pouvoir chercher par nom de société (un utilisateur n'a qu'un dossier : pas de doublons).
        $qb = $this->createQueryBuilder('p')->leftJoin('p.owner', 'o')->leftJoin(SupplierProfile::class, 'sp', 'WITH', 'sp.user = p.owner');

        $owner = (string) ($filters['owner'] ?? 'all');
        if ('gobuy' === $owner) {
            $qb->andWhere('p.owner IS NULL');
        } elseif ('all' !== $owner && '' !== $owner) {
            // Un identifiant invalide ne doit pas lever d'erreur : il ne correspond simplement à aucun produit.
            $qb->andWhere('o.id = :ownerId')->setParameter('ownerId', Uuid::isValid($owner) ? Uuid::fromString($owner) : Uuid::fromString('00000000-0000-0000-0000-000000000000'), UuidType::NAME);
        }
        if (null !== ($status = ProductStatus::tryFrom((string) ($filters['status'] ?? '')))) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }
        if (true === ($filters['flagged'] ?? false)) {
            $qb->andWhere('p.flaggedAt IS NOT NULL');
        }
        if (true === ($filters['featured'] ?? false)) {
            $qb->andWhere('p.featuredAt IS NOT NULL')->andWhere('(p.featuredUntil IS NULL OR p.featuredUntil > :now)')->setParameter('now', $now);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ('' !== $q) {
            $qb->andWhere('LOWER(p.name) LIKE :q OR LOWER(p.reference) LIKE :q OR LOWER(o.fullName) LIKE :q OR LOWER(o.email) LIKE :q OR LOWER(sp.companyName) LIKE :q')
                ->setParameter('q', '%'.addcslashes(mb_strtolower($q), '%_\\').'%');
        }

        return $qb;
    }

    // ------------------------------------------------------------------ Opérations de masse (sanctions)

    /** Retire de la vente tous les produits d'un fournisseur encore en ligne. Retourne le nombre de produits retirés. */
    public function removeAllOf(User $supplier, User $admin, string $reason, \DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE '.Product::class.' p SET p.status = :removed, p.removedAt = :now, p.removedReason = :reason, p.removedBy = :admin, p.updatedAt = :now
             WHERE p.owner = :owner AND p.status != :removed'
        )->setParameter('removed', ProductStatus::Removed)->setParameter('now', $now)->setParameter('reason', $reason)
            ->setParameter('admin', $admin)->setParameter('owner', $supplier)->execute();
    }

    /** Supprime DÉFINITIVEMENT tous les produits d'un fournisseur. Retourne le nombre de produits supprimés. */
    public function deleteAllOf(User $supplier): int
    {
        return (int) $this->getEntityManager()->createQuery('DELETE '.Product::class.' p WHERE p.owner = :owner')
            ->setParameter('owner', $supplier)->execute();
    }

    // ------------------------------------------------------------------ Vitrine

    /**
     * Produits visibles dans la boutique : actifs ET vendus par GoBuy ou par un fournisseur approuvé dont le compte est actif.
     * Les produits mis en avant passent en premier, puis les plus récents.
     *
     * @return list<Product>
     */
    public function findStorefront(int $limit, \DateTimeImmutable $now): array
    {
        return $this->sellableQuery()
            ->setParameter('now', $now)
            ->addSelect('(CASE WHEN p.featuredAt IS NOT NULL AND (p.featuredUntil IS NULL OR p.featuredUntil > :now) THEN 0 ELSE 1 END) AS HIDDEN featuredRank')
            ->orderBy('featuredRank', 'ASC')->addOrderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** Un produit de la boutique par identifiant, s'il est vendable (même règle que la liste), sinon null. */
    public function findSellable(string $id): ?Product
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->sellableQuery()->andWhere('p.id = :id')->setParameter('id', Uuid::fromString($id), UuidType::NAME)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Règle UNIQUE de « vendable » : produit actif ET (vendu par GoBuy OU par un fournisseur dont le dossier est approuvé et
     * dont le compte est actif et dont les fonds ne sont pas gelés). Un fournisseur suspendu, refusé, banni, gelé ou en cours de
     * suppression disparaît de la boutique.
     */
    private function sellableQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.owner', 'o')->addSelect('o')
            ->leftJoin(SupplierProfile::class, 'sp', 'WITH', 'sp.user = p.owner')
            ->andWhere('p.status = :active')->setParameter('active', ProductStatus::Active)
            ->andWhere('p.owner IS NULL OR (o.status = :accountActive AND o.fundsFrozenAt IS NULL AND sp.status = :approved)')
            ->setParameter('accountActive', AccountStatus::Active)
            ->setParameter('approved', SupplierStatus::Approved);
    }
}
