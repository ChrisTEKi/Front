<?php

namespace App\Repository;

use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\SupplierStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupplierProfile>
 */
class SupplierProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierProfile::class);
    }

    /** Dossier fournisseur d'un utilisateur (clé primaire = utilisateur). */
    public function findOneByUser(User $user): ?SupplierProfile
    {
        return $this->find($user);
    }

    /**
     * Dossiers d'un statut donné, les plus anciens d'abord (file d'attente de validation).
     *
     * @return list<SupplierProfile>
     */
    public function findByStatus(SupplierStatus $status, int $limit = 200): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.user', 'u')->addSelect('u')
            ->andWhere('s.status = :status')
            ->setParameter('status', $status)
            ->orderBy('s.submittedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les dossiers les plus récents, tous statuts confondus (onglet « Candidatures » de l'espace admin).
     *
     * @return list<SupplierProfile>
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.user', 'u')->addSelect('u')
            ->orderBy('s.submittedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(SupplierStatus $status): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.user)')
            ->andWhere('s.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Parcours en flux de tous les dossiers (export CSV des fournisseurs). */
    public function iterateAll(): iterable
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.user', 'u')->addSelect('u')
            ->orderBy('s.submittedAt', 'ASC')
            ->getQuery()
            ->toIterable();
    }
}
