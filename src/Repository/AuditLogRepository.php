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
     * Dernières adresses IP distinctes dont ce compte s'est servi (connexions, inscription), la plus récente d'abord.
     * Sert à la liste noire « technique ». Les entrées sans IP (console, tâches planifiées) sont ignorées.
     *
     * @return list<string>
     */
    public function findKnownIps(string $userId, int $limit = 3): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT ip FROM (
                SELECT ip_address AS ip, MAX(created_at) AS last_seen
                  FROM audit_logs
                 WHERE actor_id = :user AND ip_address IS NOT NULL
                 GROUP BY ip_address
            ) known ORDER BY last_seen DESC LIMIT '.max(1, min(10, $limit)),
            ['user' => $userId],
        );

        return array_values(array_map('strval', $rows));
    }

    /**
     * Historique d'un compte : les entrées du journal dont il est la CIBLE (décisions, sanctions, contestations…), les plus récentes d'abord.
     *
     * @return list<AuditLog>
     */
    public function findForTarget(string $targetId, int $limit = 30): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.targetId = :id')->setParameter('id', $targetId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()->getResult();
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
