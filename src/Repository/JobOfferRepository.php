<?php

namespace App\Repository;

use App\Entity\JobOffer;
use App\Enum\JobOfferStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<JobOffer>
 */
class JobOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobOffer::class);
    }

    public function findOneByUuid(string $id): ?JobOffer
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /**
     * Offres que le public peut voir : ouvertes et dont la date limite n'est pas dépassée, les plus récentes d'abord.
     *
     * @return list<JobOffer>
     */
    public function findPublic(\DateTimeImmutable $now): array
    {
        $offers = $this->createQueryBuilder('o')
            ->andWhere('o.status = :open')->setParameter('open', JobOfferStatus::Open)
            ->orderBy('o.publishedAt', 'DESC')
            ->getQuery()->getResult();

        // La date limite se compare à l'heure de Madagascar (JobOffer::acceptsApplications) : une seule règle.
        return array_values(array_filter($offers, static fn (JobOffer $o): bool => $o->acceptsApplications($now)));
    }

    /**
     * Toutes les offres pour l'administration, avec le nombre de candidatures (total et nouvelles).
     *
     * @return list<array{offer: JobOffer, total: int, fresh: int}>
     */
    public function findAllWithCounts(): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT o AS offer, COUNT(a.id) AS total, COALESCE(SUM(CASE WHEN a.status = :new THEN 1 ELSE 0 END), 0) AS fresh
             FROM App\Entity\JobOffer o LEFT JOIN App\Entity\JobApplication a WITH a.offer = o
             GROUP BY o.id ORDER BY o.createdAt DESC',
        )->setParameter('new', \App\Enum\ApplicationStatus::New)->getResult();

        return array_map(static fn (array $r): array => ['offer' => $r['offer'], 'total' => (int) $r['total'], 'fresh' => (int) $r['fresh']], $rows);
    }
}
