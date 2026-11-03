<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findOneByNumber(string $orderNumber): ?Order
    {
        return $this->findOneBy(['orderNumber' => strtoupper(trim($orderNumber))]);
    }

    public function numberExists(string $orderNumber): bool
    {
        return null !== $this->findOneBy(['orderNumber' => $orderNumber]);
    }

    /**
     * Historique des commandes d'un client, la plus récente d'abord.
     * Les lignes sont chargées en même temps (évite une requête par commande).
     *
     * @return list<Order>
     */
    public function findForClient(User $client): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.items', 'i')->addSelect('i')
            ->andWhere('o.client = :client')
            ->setParameter('client', $client->getId(), 'uuid')
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retrouve UNE commande d'un client à partir de la référence qu'il saisit (« GB-2026-482915 », « GB2026482915 », « 482915 »…).
     *
     * SÉCURITÉ : la recherche est TOUJOURS limitée aux commandes de ce client. Même en devinant un numéro, on ne peut
     * jamais voir la commande de quelqu'un d'autre (et « inexistante » et « pas la vôtre » donnent la même réponse).
     */
    public function findForClientByReference(User $client, string $reference): ?Order
    {
        // On ne garde que lettres et chiffres : « gb-2026-482 915 » → « GB2026482915 ».
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $reference));
        if (1 !== preg_match('/^(?:GB)?(\d{4})?(\d{6})$/', $clean, $m)) {
            return null;
        }
        [, $year, $digits] = $m;

        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.client = :client')
            ->setParameter('client', $client->getId(), 'uuid')
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults(1);
        if ('' !== $year) {
            $qb->andWhere('o.orderNumber = :number')->setParameter('number', \sprintf('GB-%s-%s', $year, $digits));
        } else {
            // Seulement les 6 derniers chiffres : on cherche parmi les commandes du client (peu nombreuses).
            $qb->andWhere('o.orderNumber LIKE :suffix')->setParameter('suffix', '%-'.$digits);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Commandes dont l'e-mail de confirmation n'est pas parti (échec temporaire du serveur d'e-mails).
     *
     * @return list<Order>
     */
    public function findWithoutConfirmationEmail(int $limit = 100): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.confirmationEmailSentAt IS NULL')
            ->andWhere('o.customerEmail IS NOT NULL')
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les commandes, de la plus ancienne à la plus récente, lues « en flux » (exports CSV : pas tout en mémoire).
     *
     * @return iterable<Order>
     */
    public function iterateAll(): iterable
    {
        return $this->createQueryBuilder('o')->orderBy('o.createdAt', 'ASC')->getQuery()->toIterable();
    }

    /**
     * Commandes récentes pour le tableau de bord administrateur.
     *
     * @return list<Order>
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('o')->orderBy('o.createdAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * Nombre de commandes EN COURS d'un client (ni livrées ni annulées). Tant qu'il y en a, le compte ne peut pas être supprimé.
     */
    public function countOpenForClient(User $client): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.client = :client')
            ->andWhere('o.status NOT IN (:closed)')
            ->setParameter('client', $client)
            ->setParameter('closed', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de commandes EN COURS (ni livrées ni annulées) qui contiennent encore un article NON remboursé d'un fournisseur.
     * Tant qu'il y en a, son compte ne peut pas être supprimé : le colis est en route, l'acheteur attend sa marchandise.
     */
    public function countOpenForSupplier(User $supplier): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM orders o
              WHERE o.status NOT IN ('delivered', 'cancelled')
                AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id AND i.supplier_id = :supplier AND i.refunded_at IS NULL)",
            ['supplier' => $supplier->getId()->toRfc4122()],
        );
    }

    /** Nombre de commandes d'un client (toutes, quel que soit leur état). */
    public function countForClient(User $client): int
    {
        return (int) $this->createQueryBuilder('o')->select('COUNT(o.id)')
            ->andWhere('o.client = :client')->setParameter('client', $client)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Identifiants des commandes dont au moins une ligne d'UN fournisseur n'a pas encore été remboursée (plus anciennes d'abord).
     * Les commandes annulées sont exclues ; les commandes livrées ne comptent que si `$includeDelivered`.
     *
     * @return list<string>
     */
    public function idsRefundableForSupplier(User $supplier, bool $includeDelivered, int $limit): array
    {
        return array_values(array_map('strval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT o.id FROM orders o
              WHERE o.status IN ('.$this->statusList($includeDelivered).')
                AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id AND i.supplier_id = :supplier AND i.refunded_at IS NULL)
              ORDER BY o.created_at ASC, o.id ASC
              LIMIT '.max(1, $limit),
            ['supplier' => $supplier->getId()->toRfc4122()],
        )));
    }

    public function countRefundableForSupplier(User $supplier, bool $includeDelivered): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM orders o
              WHERE o.status IN ('.$this->statusList($includeDelivered).')
                AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id AND i.supplier_id = :supplier AND i.refunded_at IS NULL)',
            ['supplier' => $supplier->getId()->toRfc4122()],
        );
    }

    /**
     * Commandes EN COURS d'un client (ni livrées ni annulées), lignes comprises, plus anciennes d'abord.
     *
     * @return list<Order>
     */
    public function findOpenForClient(User $client, int $limit): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.items', 'i')->addSelect('i')
            ->andWhere('o.client = :client')->setParameter('client', $client->getId(), 'uuid')
            ->andWhere('o.status NOT IN (:closed)')->setParameter('closed', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Commandes chargées avec TOUTES leurs lignes (pour décider si la commande est remboursée en entier).
     *
     * @param list<string> $ids
     *
     * @return list<Order>
     */
    public function findWithItems(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('o')
            ->leftJoin('o.items', 'i')->addSelect('i')
            ->andWhere('o.id IN (:ids)')->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()->getResult();
    }

    /** Liste SQL des statuts de commande concernés par un remboursement (constantes de l'enum : aucune saisie utilisateur). */
    private function statusList(bool $includeDelivered): string
    {
        $statuses = [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::OutForDelivery];
        if ($includeDelivered) {
            $statuses[] = OrderStatus::Delivered;
        }

        return implode(', ', array_map(static fn (OrderStatus $s): string => "'".$s->value."'", $statuses));
    }

    /** Nombre total de commandes (tableau de bord admin). */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('o')->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();
    }
}
