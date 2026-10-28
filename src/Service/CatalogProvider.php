<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class CatalogProvider
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    public function findActiveProducts(?string $categorySlug = null, int $limit = 24): array
    {
        $sql = <<<'SQL'
            SELECT p.id, p.name, p.description, p.price, p.stock, p.rating_avg,
                   p.reviews_count, u.first_name, u.last_name,
                   (SELECT pi.path FROM product_image pi WHERE pi.product_id = p.id ORDER BY pi.position LIMIT 1) AS image_path
            FROM product p
            INNER JOIN public."user" u ON u.id = p.supplier_id
            INNER JOIN category c ON c.id = p.category_id
            WHERE p.status = 'active' AND p.stock > 0
            SQL;
        $params = [];

        if (null !== $categorySlug) {
            $sql .= ' AND c.slug = :categorySlug';
            $params['categorySlug'] = $categorySlug;
        }

        $limit = max(1, min($limit, 100));
        $sql .= sprintf(' ORDER BY p.created_at DESC LIMIT %d', $limit);
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map($this->mapProduct(...), $rows);
    }

    /** @return array<string, mixed>|null */
    public function findActiveProduct(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT p.id, p.name, p.description, p.price, p.stock, p.rating_avg,
                       p.reviews_count, u.first_name, u.last_name,
                       (SELECT pi.path FROM product_image pi WHERE pi.product_id = p.id ORDER BY pi.position LIMIT 1) AS image_path
                FROM product p
                INNER JOIN public."user" u ON u.id = p.supplier_id
                WHERE p.id = :id AND p.status = 'active'
                SQL,
            ['id' => $id],
        );

        return false === $row ? null : $this->mapProduct($row);
    }

    /** @return array<string, int> */
    public function countActiveProductsByCategory(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.slug, COUNT(p.id) AS product_count
             FROM category c
             LEFT JOIN product p ON p.category_id = c.id AND p.status = 'active' AND p.stock > 0
             GROUP BY c.slug"
        );
        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['slug']] = (int) $row['product_count'];
        }

        return $counts;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function mapProduct(array $row): array
    {
        $imagePath = $row['image_path'];

        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'price' => (float) $row['price'],
            'stock' => (int) $row['stock'],
            'rating' => (float) $row['rating_avg'],
            'reviewsCount' => (int) $row['reviews_count'],
            'supplierName' => trim($row['first_name'].' '.$row['last_name']),
            'brand' => '',
            'images' => null !== $imagePath ? [$imagePath] : [],
            'originalPrice' => null,
            'flashDeal' => false,
        ];
    }
}