<?php

namespace App\Repository;

use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\AccountType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Requêtes sur la table `users`.
 *
 * Étend ServiceEntityRepository : Symfony l'injecte automatiquement là où on le demande
 * (`private UserRepository $users`). PasswordUpgraderInterface permet à Symfony de re-hacher
 * un mot de passe avec un algorithme plus récent lors d'une connexion réussie.
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** Retrouve un compte par e-mail (insensible à la casse : on normalise en minuscules). */
    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /** Retrouve le compte qui possède ce numéro de téléphone VÉRIFIÉ (E.164), s'il existe. */
    public function findOneByVerifiedPhone(string $phoneE164): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.phone = :phone')
            ->andWhere('u.phoneVerifiedAt IS NOT NULL')
            ->setParameter('phone', $phoneE164)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Retrouve un compte par identifiant (UUID au format texte), ou null si invalide/inconnu. */
    public function findOneByUuid(string $id): ?User
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->find(Uuid::fromString($id));
    }

    /** Persiste un utilisateur (insert ou update) et écrit immédiatement en base. */
    public function save(User $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** Appelé par Symfony pour remplacer un hash obsolète par un hash moderne après une connexion réussie. */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances de "%s" non supportées.', $user::class));
        }
        $user->setPasswordHash($newHashedPassword, new \DateTimeImmutable());
        $this->save($user);
    }

    /** Existe-t-il déjà au moins un Super Admin ? (utilisé par la commande de création) */
    public function superAdminExists(): bool
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM users WHERE roles @> '[\"ROLE_SUPER_ADMIN\"]'::jsonb"
        ) > 0;
    }

    /**
     * Identifiants des comptes CLIENTS dont la suppression est programmée et dont le délai de grâce (30 jours) est écoulé.
     * Les plus anciennes demandes d'abord. On ne renvoie que les identifiants : la purge recharge chaque compte
     * elle-même, sous verrou, juste avant de l'effacer.
     *
     * @return list<string>
     */
    public function findIdsDueForPurge(\DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('u.id')
            ->andWhere('u.status = :status')
            ->andWhere('u.accountType = :type')
            ->andWhere('u.deletionScheduledAt IS NOT NULL')
            ->andWhere('u.deletionScheduledAt <= :now')
            ->setParameter('status', AccountStatus::PendingDeletion)
            ->setParameter('type', AccountType::Client)
            ->setParameter('now', $now)
            ->orderBy('u.deletionScheduledAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getSingleColumnResult();

        // getSingleColumnResult() rend les valeurs brutes de la base (texte) : on garantit des chaînes UUID.
        return array_map(static fn (string|Uuid $id): string => (string) $id, $rows);
    }

    /** Nombre de comptes d'un type donné. */
    public function countByType(AccountType $type): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.accountType = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Liste paginée de comptes d'un type, avec recherche simple (nom ou e-mail).
     *
     * @return list<User>
     */
    public function search(AccountType $type, ?string $query, int $page, int $perPage): array
    {
        return $this->searchQuery($type, $query)
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC') // départage les comptes créés à la même seconde : la pagination reste stable
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /** Nombre total de comptes correspondant à la même recherche (pour afficher « page 2 sur 5 »). */
    public function countSearch(AccountType $type, ?string $query): int
    {
        return (int) $this->searchQuery($type, $query)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
    }

    /** Filtre commun à search() et countSearch() : type de compte + texte éventuel. */
    private function searchQuery(AccountType $type, ?string $query): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.accountType = :type')
            ->setParameter('type', $type);

        if (null !== $query && '' !== trim($query)) {
            // LIKE avec échappement des jokers saisis par l'utilisateur (% et _) pour éviter les recherches abusives.
            $like = '%'.addcslashes(mb_strtolower(trim($query)), '%_\\').'%';
            // orX() entoure explicitement le « OU » de parenthèses : sans cela le filtre sur le type de compte pourrait être contourné.
            $qb->andWhere($qb->expr()->orX('LOWER(u.fullName) LIKE :q', 'LOWER(u.email) LIKE :q'))->setParameter('q', $like);
        }

        return $qb;
    }

    /** Parcours "en flux" de tous les comptes d'un type (exports CSV) : ne charge pas tout en mémoire. */
    public function iterateByType(AccountType $type): iterable
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.accountType = :type')
            ->setParameter('type', $type)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->toIterable();
    }
}
