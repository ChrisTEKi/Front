<?php

namespace App\Repository;

use App\Entity\JobApplication;
use App\Entity\JobOffer;
use App\Enum\ApplicationStatus;
use App\Enum\InterviewStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<JobApplication>
 */
class JobApplicationRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobApplication::class);
    }

    public function findOneByUuid(string $id): ?JobApplication
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    public function findOneByOfferAndEmail(JobOffer $offer, string $email): ?JobApplication
    {
        return $this->findOneBy(['offer' => $offer, 'email' => mb_strtolower($email)]);
    }

    /**
     * Candidatures d'une offre, avec filtre d'état facultatif.
     *
     * @param 'asc'|'desc' $order selon la date de candidature
     *
     * @return list<JobApplication>
     */
    public function forOffer(JobOffer $offer, ?ApplicationStatus $status, string $order = 'desc', int $page = 1): array
    {
        return $this->offerQuery($offer, $status)
            ->orderBy('a.createdAt', 'asc' === $order ? 'ASC' : 'DESC')->addOrderBy('a.id', 'ASC')
            ->setFirstResult((max(1, $page) - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)
            ->getQuery()->getResult();
    }

    public function countForOffer(JobOffer $offer, ?ApplicationStatus $status): int
    {
        return (int) $this->offerQuery($offer, $status)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
    }

    private function offerQuery(JobOffer $offer, ?ApplicationStatus $status): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')->andWhere('a.offer = :o')->setParameter('o', $offer->getId(), UuidType::NAME);
        if (null !== $status) {
            $qb->andWhere('a.status = :s')->setParameter('s', $status);
        }

        return $qb;
    }

    public function countForOfferAll(JobOffer $offer): int
    {
        return $this->countForOffer($offer, null);
    }

    /**
     * Candidats ayant au moins un entretien (planning), du plus ancien au plus récent selon la date de CANDIDATURE par défaut.
     * Leurs entretiens sont chargés avec eux.
     *
     * @param 'asc'|'desc'         $order
     * @param 'all'|'upcoming'     $scope « upcoming » = au moins un entretien encore à venir (non annulé)
     *
     * @return list<JobApplication>
     */
    public function planning(string $order, string $scope, ?JobOffer $offer, \DateTimeImmutable $now, int $page = 1): array
    {
        $ids = $this->planningIds($order, $scope, $offer, $now, (max(1, $page) - 1) * self::PER_PAGE, self::PER_PAGE);
        if ([] === $ids) {
            return [];
        }

        $found = $this->createQueryBuilder('a')
            ->addSelect('i', 'o')
            ->join('a.offer', 'o')
            ->leftJoin('a.interviews', 'i')
            ->andWhere('a.id IN (:ids)')->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->orderBy('i.scheduledAt', 'ASC')
            ->getQuery()->getResult();

        // On restitue l'ordre de la première requête (date de candidature), pas celui de la seconde.
        $byId = [];
        foreach ($found as $application) {
            $byId[$application->getId()->toRfc4122()] = $application;
        }

        return array_values(array_filter(array_map(static fn (string $id): ?JobApplication => $byId[$id] ?? null, $ids)));
    }

    public function countPlanning(string $scope, ?JobOffer $offer, \DateTimeImmutable $now): int
    {
        return \count($this->planningIds('asc', $scope, $offer, $now, 0, null));
    }

    /** @return list<string> */
    private function planningIds(string $order, string $scope, ?JobOffer $offer, \DateTimeImmutable $now, int $offset, ?int $limit): array
    {
        $qb = $this->createQueryBuilder('a')->select('a.id AS id')->distinct()->addSelect('a.createdAt AS HIDDEN created')
            ->join('a.interviews', 'i');
        if ('upcoming' === $scope) {
            $qb->andWhere('i.status = :scheduled')->setParameter('scheduled', InterviewStatus::Scheduled)
                ->andWhere('i.scheduledAt >= :now')->setParameter('now', $now);
        }
        if (null !== $offer) {
            $qb->andWhere('a.offer = :o')->setParameter('o', $offer->getId(), UuidType::NAME);
        }
        $qb->orderBy('a.createdAt', 'asc' === $order ? 'ASC' : 'DESC')->addOrderBy('a.id', 'ASC')->setFirstResult($offset);
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return array_map(static fn (array $row): string => $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : (string) $row['id'], $qb->getQuery()->getResult());
    }

    /**
     * Candidatures dont la dernière activité (décision, sinon dépôt) est antérieure à la date donnée : à effacer.
     *
     * @return list<JobApplication>
     */
    public function findExpired(\DateTimeImmutable $before, int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('COALESCE(a.decidedAt, a.createdAt) < :before')->setParameter('before', $before)
            ->orderBy('a.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
