<?php

namespace App\Repository;

use App\Entity\SupplierAppeal;
use App\Entity\User;
use App\Enum\AppealStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SupplierAppeal>
 */
class SupplierAppealRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierAppeal::class);
    }

    public function findOneByUuid(string $id): ?SupplierAppeal
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /**
     * Contestations d'un fournisseur, les plus récentes d'abord.
     *
     * @return list<SupplierAppeal>
     */
    public function findForSupplier(User $supplier): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.supplier = :s')->setParameter('s', $supplier->getId(), UuidType::NAME)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Contestations encore ouvertes d'un fournisseur.
     *
     * @return list<SupplierAppeal>
     */
    public function findOpenForSupplier(User $supplier): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.supplier = :s')->setParameter('s', $supplier->getId(), UuidType::NAME)
            ->andWhere('a.status = :open')->setParameter('open', AppealStatus::Open)
            ->getQuery()->getResult();
    }

    /** Nombre de contestations déposées depuis la dernière décision de l'équipe (limite par décision). */
    public function countSince(User $supplier, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.supplier = :s')->setParameter('s', $supplier->getId(), UuidType::NAME)
            ->andWhere('a.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    /** Nombre de contestations en attente d'examen (tous fournisseurs). */
    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.status = :open')->setParameter('open', AppealStatus::Open)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Fournisseurs (identifiants texte) qui ont au moins une contestation ouverte : pour signaler la ligne dans les listes admin.
     *
     * @return array<string, int> identifiant du fournisseur => nombre de contestations ouvertes
     */
    public function openCountsBySupplier(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.supplier) AS supplier, COUNT(a.id) AS total')
            ->andWhere('a.status = :open')->setParameter('open', AppealStatus::Open)
            ->groupBy('a.supplier')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['supplier']] = (int) $row['total'];
        }

        return $counts;
    }
}
