<?php

namespace App\Repository;

use App\Entity\BlacklistEntry;
use App\Enum\BlacklistKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BlacklistEntry>
 */
class BlacklistEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlacklistEntry::class);
    }

    public function findOneByUuid(string $id): ?BlacklistEntry
    {
        return Uuid::isValid($id) ? $this->find($id) : null;
    }

    public function findOneByKindAndValue(BlacklistKind $kind, string $value): ?BlacklistEntry
    {
        return $this->findOneBy(['kind' => $kind, 'value' => $value]);
    }

    /**
     * Entrées ACTIVES (non expirées) correspondant à l'une des valeurs données : une seule requête pour tout un formulaire.
     *
     * @param array<string, list<string>> $candidates type (« email »…) => valeurs normalisées à tester
     *
     * @return list<BlacklistEntry>
     */
    public function findActiveMatching(array $candidates, \DateTimeImmutable $now): array
    {
        $found = [];
        foreach ($candidates as $kind => $values) {
            if ([] === $values) {
                continue;
            }
            $rows = $this->createQueryBuilder('b')
                ->andWhere('b.kind = :kind')->setParameter('kind', BlacklistKind::from($kind))
                ->andWhere('b.value IN (:values)')->setParameter('values', $values)
                ->andWhere('b.expiresAt IS NULL OR b.expiresAt > :now')->setParameter('now', $now)
                ->getQuery()->getResult();
            array_push($found, ...$rows);
        }

        return $found;
    }

    /**
     * @return list<BlacklistEntry> les plus récentes d'abord
     */
    public function findRecent(int $limit = 200): array
    {
        return $this->createQueryBuilder('b')->orderBy('b.createdAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('b')->select('COUNT(b.id)')->getQuery()->getSingleScalarResult();
    }
}
