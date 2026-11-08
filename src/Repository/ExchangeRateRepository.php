<?php

namespace App\Repository;

use App\Entity\ExchangeRate;
use App\Enum\Currency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExchangeRate>
 */
class ExchangeRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeRate::class);
    }

    /** Taux en vigueur : la ligne la plus récente de la devise. */
    public function latestFor(Currency $currency): ?ExchangeRate
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.currency = :c')
            ->setParameter('c', $currency)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Dernier taux AUTOMATIQUE de la devise (même si un taux manuel est venu après) : sert au garde-fou contre les écarts anormaux.
     */
    public function latestAutomaticFor(Currency $currency): ?ExchangeRate
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.currency = :c')
            ->andWhere('r.source = :s')
            ->setParameter('c', $currency)
            ->setParameter('s', ExchangeRate::SOURCE_AUTO)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Historique d'une devise, du plus récent au plus ancien.
     *
     * @return list<ExchangeRate>
     */
    public function history(Currency $currency, int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.currency = :c')
            ->setParameter('c', $currency)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
