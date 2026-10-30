<?php

namespace App\Repository;

use App\Entity\OneTimeCode;
use App\Entity\User;
use App\Enum\OtpPurpose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes sur `one_time_codes`.
 *
 * @extends ServiceEntityRepository<OneTimeCode>
 */
class OneTimeCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OneTimeCode::class);
    }

    /**
     * Dernier code ÉMIS (même consommé) pour un utilisateur et une finalité : sert à imposer
     * un délai minimal entre deux envois (« renvoyer le code dans 30 s »).
     */
    public function findLatest(User $user, OtpPurpose $purpose): ?OneTimeCode
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.purpose = :purpose')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('purpose', $purpose)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Code actif (non consommé) le plus récent d'un utilisateur pour une finalité.
     * On ne vérifie volontairement QUE le dernier code émis : en demander un nouveau annule les précédents.
     */
    public function findActive(User $user, OtpPurpose $purpose): ?OneTimeCode
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.purpose = :purpose')
            ->andWhere('c.consumedAt IS NULL')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('purpose', $purpose)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Invalide (consomme) tous les codes encore actifs d'un utilisateur pour une finalité. */
    public function invalidateActive(User $user, OtpPurpose $purpose, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('c')
            ->update()
            ->set('c.consumedAt', ':now')
            ->andWhere('c.user = :user')
            ->andWhere('c.purpose = :purpose')
            ->andWhere('c.consumedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('purpose', $purpose)
            ->getQuery()
            ->execute();
    }

    /** Supprime les codes expirés depuis plus de $olderThan (nettoyage planifié). Retourne le nombre de lignes effacées. */
    public function purgeExpired(\DateTimeImmutable $olderThan): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.expiresAt < :limit')
            ->setParameter('limit', $olderThan)
            ->getQuery()
            ->execute();
    }
}
