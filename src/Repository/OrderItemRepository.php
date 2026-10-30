<?php

namespace App\Repository;

use App\Entity\OrderItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderItem>
 */
class OrderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderItem::class);
    }

    /**
     * Ventes cumulées par fournisseur (Ariary), commandes annulées exclues.
     * Une seule requête SQL pour tous les fournisseurs (utile aux exports et au tableau de bord).
     *
     * @return array<string, int> identifiant du fournisseur (UUID texte) => total des ventes
     */
    public function salesTotalsBySupplier(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            "SELECT i.supplier_id::text, SUM(i.unit_price * i.quantity)
               FROM order_items i
               JOIN orders o ON o.id = i.order_id
              WHERE i.supplier_id IS NOT NULL AND o.status <> 'cancelled'
              GROUP BY i.supplier_id"
        );

        return array_map('intval', $rows);
    }

    /**
     * Chiffres clés d'UN fournisseur : commandes reçues, en préparation, livrées et ventes cumulées (Ariary).
     * Les commandes annulées ne comptent pas. Une seule requête SQL.
     *
     * @return array{orders: int, preparing: int, delivered: int, salesTotal: int}
     */
    public function statsForSupplier(string $supplierId): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            "SELECT COUNT(DISTINCT o.id) AS orders,
                    COUNT(DISTINCT o.id) FILTER (WHERE o.status IN ('confirmed', 'preparing', 'out_for_delivery')) AS preparing,
                    COUNT(DISTINCT o.id) FILTER (WHERE o.status = 'delivered') AS delivered,
                    COALESCE(SUM(i.unit_price * i.quantity), 0) AS sales_total
               FROM order_items i
               JOIN orders o ON o.id = i.order_id
              WHERE i.supplier_id = :supplier AND o.status <> 'cancelled'",
            ['supplier' => $supplierId],
        ) ?: [];

        return [
            'orders' => (int) ($row['orders'] ?? 0),
            'preparing' => (int) ($row['preparing'] ?? 0),
            'delivered' => (int) ($row['delivered'] ?? 0),
            'salesTotal' => (int) ($row['sales_total'] ?? 0),
        ];
    }

    /**
     * Ventes d'un fournisseur jour par jour depuis `$from` (jours calendaires à l'heure de Madagascar), commandes annulées exclues.
     *
     * @return array<string, array{orders: int, sales: int}> « 2026-10-23 » => chiffres du jour (les jours sans vente sont absents)
     */
    public function dailySalesForSupplier(string $supplierId, \DateTimeImmutable $from): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT to_char(o.created_at AT TIME ZONE 'Indian/Antananarivo', 'YYYY-MM-DD') AS day,
                    COUNT(DISTINCT o.id) AS orders,
                    SUM(i.unit_price * i.quantity) AS sales
               FROM order_items i
               JOIN orders o ON o.id = i.order_id
              WHERE i.supplier_id = :supplier AND o.status <> 'cancelled' AND o.created_at >= :from
              GROUP BY 1",
            ['supplier' => $supplierId, 'from' => $from->format('Y-m-d H:i:sP')],
        );

        $days = [];
        foreach ($rows as $row) {
            $days[(string) $row['day']] = ['orders' => (int) $row['orders'], 'sales' => (int) $row['sales']];
        }

        return $days;
    }

    /**
     * Commandes contenant des articles d'UN fournisseur, les plus récentes d'abord.
     * Volontairement SANS données personnelles du client (nom, téléphone, adresse) : le fournisseur prépare le colis,
     * le coursier GoBuy s'occupe de la livraison.
     *
     * @return list<array{number: string, status: string, createdAt: \DateTimeImmutable, lines: list<string>, total: int}>
     */
    public function recentOrdersForSupplier(string $supplierId, int $limit = 50): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT o.order_number, o.status, o.created_at,
                    SUM(i.unit_price * i.quantity) AS total,
                    string_agg(i.quantity || ' × ' || i.product_name, E'\n' ORDER BY i.id) AS lines
               FROM order_items i
               JOIN orders o ON o.id = i.order_id
              WHERE i.supplier_id = :supplier
              GROUP BY o.id, o.order_number, o.status, o.created_at
              ORDER BY o.created_at DESC
              LIMIT ".max(1, min(200, $limit)),
            ['supplier' => $supplierId],
        );

        return array_map(static fn (array $row): array => [
            'number' => (string) $row['order_number'],
            'status' => (string) $row['status'],
            'createdAt' => new \DateTimeImmutable((string) $row['created_at']),
            'lines' => explode("\n", (string) $row['lines']),
            'total' => (int) $row['total'],
        ], $rows);
    }
}
