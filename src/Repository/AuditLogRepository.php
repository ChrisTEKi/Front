<?php

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lecture du journal d'audit. Il n'existe volontairement AUCUNE méthode de modification ni de suppression
 * (hors purge de rétention gérée en base par l'exploitant).
 *
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Dernières entrées, éventuellement filtrées par préfixe d'action (ex. « admin. »).
     *
     * @return list<AuditLog>
     */
    public function findRecent(int $page, int $perPage, ?string $actionPrefix = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        if (null !== $actionPrefix && '' !== $actionPrefix) {
            $qb->andWhere('a.action LIKE :prefix')->setParameter('prefix', addcslashes($actionPrefix, '%_\\').'%');
        }

        return $qb->getQuery()->getResult();
    }
}
