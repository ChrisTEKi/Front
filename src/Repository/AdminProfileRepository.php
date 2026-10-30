<?php

namespace App\Repository;

use App\Entity\AdminProfile;
use App\Entity\AdminRole;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminProfile>
 */
class AdminProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminProfile::class);
    }

    public function findOneByUser(User $user): ?AdminProfile
    {
        return $this->find($user);
    }

    /**
     * Tous les profils d'administrateurs avec leur compte et leur profil de droits (un seul SELECT).
     *
     * @return list<AdminProfile>
     */
    public function findAllWithDetails(): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.user', 'u')->addSelect('u')
            ->innerJoin('p.role', 'r')->addSelect('r')
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Combien d'admins utilisent ce profil ? (on interdit de supprimer un profil utilisé) */
    public function countByRole(AdminRole $role): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.user)')
            ->andWhere('p.role = :role')
            ->setParameter('role', $role->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
