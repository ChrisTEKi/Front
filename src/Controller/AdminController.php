<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use App\Security\DatabaseUser;

final class AdminController extends AbstractController
{
    private const ORDER_STATUSES = [
        'pending' => 'En attente',
        'confirmed' => 'Confirmée',
        'preparing' => 'En préparation',
        'shipped' => 'Expédiée',
        'delivered' => 'Livrée',
        'cancelled' => 'Annulée',
    ];

    private const ORDER_STATUS_TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    private const PAYMENT_STATUSES = [
        'pending' => 'En attente',
        'paid' => 'Payé',
        'failed' => 'Échoué',
    ];

    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(Connection $connection): Response
    {
        $users = $connection->fetchAllAssociative(
            'SELECT id, CONCAT(first_name, \' \', last_name) AS full_name, email, roles,
                    is_email_verified AS email_verified, phone,
                    is_phone_verified AS phone_verified, is_totp_enabled AS two_factor_enabled,
                    created_at, CASE WHEN supplier_profile.id IS NULL THEN 0 ELSE 1 END AS has_supplier_profile
             FROM public."user"
             LEFT JOIN supplier_profile ON supplier_profile.user_id = "user".id
             ORDER BY "user".created_at DESC'
        );
        foreach ($users as &$user) {
            if (is_string($user['roles'])) {
                $user['roles'] = json_decode($user['roles'], true) ?? [];
            }

            if (in_array('ROLE_ADMIN', $user['roles'], true)) {
                $user['account_type'] = 'admin';
            } elseif (1 === (int) $user['has_supplier_profile']) {
                $user['account_type'] = 'supplier';
            } elseif (array_intersect(['ROLE_COURIER', 'ROLE_DELIVERY', 'ROLE_DRIVER'], $user['roles'])) {
                $user['account_type'] = 'courier';
            } else {
                $user['account_type'] = 'client';
            }
        }
        unset($user);

        $orders = $connection->fetchAllAssociative(
            'SELECT o.id, o.order_number, o.total AS total_amount, o.payment_method, o.status,
                o.payment_status, o.created_at, u.first_name, u.last_name
             FROM orders o
             LEFT JOIN "user" u ON u.id = o.client_id
             ORDER BY o.created_at DESC'
        );
        foreach ($orders as &$order) {
            $order['customer_name'] = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
        }
        unset($order);

        $products = $connection->fetchAllAssociative(
            'SELECT p.id, p.name, p.slug, p.price, p.stock, p.status, p.created_at,
                c.name AS category_name,
                COALESCE(sp.shop_name, CONCAT(u.first_name, \' \', u.last_name)) AS supplier_name
             FROM product p
             INNER JOIN category c ON c.id = p.category_id
             LEFT JOIN public."user" u ON u.id = p.supplier_id
             LEFT JOIN supplier_profile sp ON sp.user_id = u.id
             ORDER BY p.created_at DESC'
        );
        $vendorApplications = $connection->fetchAllAssociative(
            'SELECT sp.id, sp.shop_name AS company_name, u.first_name, u.last_name, u.email, u.phone,
                    COALESCE(u.is_email_verified, false) AS email_verified,
                    COALESCE(u.is_phone_verified, false) AS phone_verified,
                    sp.status, sp.created_at
             FROM supplier_profile sp
             INNER JOIN public."user" u ON u.id = sp.user_id
             ORDER BY sp.created_at DESC'
        );
        foreach ($vendorApplications as &$application) {
            $application['contact_name'] = trim($application['first_name'] . ' ' . $application['last_name']);
            $application['type'] = 'supplier';
        }
        unset($application);

        $pendingSuppliers = array_values(array_filter(
            $vendorApplications,
            static fn (array $supplier): bool => $supplier['status'] === 'pending'
        ));
        $approvedSuppliers = count(array_filter(
            $vendorApplications,
            static fn (array $supplier): bool => $supplier['status'] === 'approved'
        ));

        // La base actuelle ne possède pas de table de reversements.
        $vendorPayouts = [];

        $payoutTotals = [
            'grossSales' => 0,
            'commissionAmount' => 0,
            'planFee' => 0,
            'pendingNetAmount' => 0,
        ];
        foreach ($vendorPayouts as $payout) {
            $payoutTotals['grossSales'] += $payout->getGrossSales();
            $payoutTotals['commissionAmount'] += $payout->getCommissionAmount();
            $payoutTotals['planFee'] += $payout->getPlanFee();
            if ($payout->getStatus() === 'pending') {
                $payoutTotals['pendingNetAmount'] += $payout->getNetAmount();
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'users' => $users,
            'orders' => $orders,
            'products' => $products,
            'categories' => $connection->fetchAllAssociative('SELECT id, name FROM category ORDER BY name'),
            'productSuppliers' => $connection->fetchAllAssociative(
                "SELECT u.id, sp.shop_name FROM supplier_profile sp
                 INNER JOIN public.\"user\" u ON u.id = sp.user_id
                 WHERE sp.status = 'approved' ORDER BY sp.shop_name"
            ),
            'vendorApplications' => $vendorApplications,
            'vendorPayouts' => $vendorPayouts,
            'pendingSuppliers' => $pendingSuppliers,
            'approvedSuppliers' => $approvedSuppliers,
            'payoutTotals' => $payoutTotals,
        ]);
    }

    #[Route(
        '/admin/orders/{id}',
        name: 'app_admin_order_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET']
    )]
    public function showOrder(string $id, Connection $connection): Response
    {
        $order = $connection->fetchAssociative(
            'SELECT o.id, o.order_number, o.status, o.payment_status, o.payment_method,
                    o.mobile_money_operator, o.mobile_money_phone, o.subtotal, o.shipping_fee,
                    o.total, o.created_at, o.confirmed_at,
                    u.id AS client_id, u.first_name, u.last_name, u.email AS client_email, u.phone AS client_phone,
                    a.recipient_name, a.phone AS shipping_phone, a.line1, a.city, a.label AS address_label
             FROM orders o
             INNER JOIN address a ON a.id = o.shipping_address_id
             LEFT JOIN public."user" u ON u.id = o.client_id
             WHERE o.id = :id',
            ['id' => $id],
        );

        if (false === $order) {
            throw $this->createNotFoundException('Commande introuvable.');
        }

        return $this->render('admin/order_detail.html.twig', [
            'order' => $order,
            'items' => $connection->fetchAllAssociative(
                'SELECT oi.product_name, oi.unit_price, oi.quantity, oi.line_total,
                        COALESCE(sp.shop_name, CONCAT(u.first_name, \' \', u.last_name)) AS supplier_name
                 FROM order_item oi
                 LEFT JOIN public."user" u ON u.id = oi.supplier_id
                 LEFT JOIN supplier_profile sp ON sp.user_id = u.id
                 WHERE oi.order_id = :orderId',
                ['orderId' => $id],
            ),
            'history' => $connection->fetchAllAssociative(
                'SELECT status, note, created_at
                 FROM order_status_history
                 WHERE order_id = :orderId
                 ORDER BY created_at DESC',
                ['orderId' => $id],
            ),
            'orderStatuses' => $this->getAvailableOrderStatuses($order['status']),
            'paymentStatuses' => self::PAYMENT_STATUSES,
        ]);
    }

    #[Route(
        '/admin/orders/{id}/update',
        name: 'app_admin_order_update',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST']
    )]
    public function updateOrder(
        string $id,
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response {
        $token = new CsrfToken('order-update-'.$id, (string) $request->request->get('_token', ''));
        if (!$csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedException('Jeton CSRF invalide.');
        }

        $status = (string) $request->request->get('status', '');
        $paymentStatus = (string) $request->request->get('payment_status', '');
        if (!isset(self::ORDER_STATUSES[$status], self::PAYMENT_STATUSES[$paymentStatus])) {
            $this->addFlash('warning', 'Le statut de commande ou de paiement est invalide.');

            return $this->redirectToRoute('app_admin_order_show', ['id' => $id]);
        }

        $connection->beginTransaction();
        try {
            $currentOrder = $connection->fetchAssociative(
                'SELECT status, payment_status FROM orders WHERE id = :id FOR UPDATE',
                ['id' => $id],
            );
            if (false === $currentOrder) {
                $connection->rollBack();
                throw $this->createNotFoundException('Commande introuvable.');
            }

            if ($currentOrder['status'] !== $status
                && !in_array($status, self::ORDER_STATUS_TRANSITIONS[$currentOrder['status']] ?? [], true)
            ) {
                $connection->rollBack();
                $this->addFlash('warning', 'Cette transition de commande n’est pas autorisée.');

                return $this->redirectToRoute('app_admin_order_show', ['id' => $id]);
            }

            $connection->executeStatement(
                'UPDATE orders
                 SET status = :status,
                     payment_status = :payment_status,
                     confirmed_at = COALESCE(confirmed_at, :confirmed_at)
                 WHERE id = :id',
                [
                    'status' => $status,
                    'payment_status' => $paymentStatus,
                    'confirmed_at' => in_array($status, ['confirmed', 'preparing', 'shipped', 'delivered'], true)
                        ? (new \DateTimeImmutable())->format('Y-m-d H:i:s')
                        : null,
                    'id' => $id,
                ],
            );

            if ($currentOrder['status'] !== $status) {
                $connection->insert('order_status_history', [
                    'id' => $connection->fetchOne('SELECT gen_random_uuid()'),
                    'order_id' => $id,
                    'status' => $status,
                    'note' => 'Statut mis à jour par un administrateur.',
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            }

            if ($currentOrder['payment_status'] !== $paymentStatus) {
                $connection->insert('order_status_history', [
                    'id' => $connection->fetchOne('SELECT gen_random_uuid()'),
                    'order_id' => $id,
                    'status' => $status,
                    'note' => sprintf('Statut de paiement : %s → %s.', $currentOrder['payment_status'], $paymentStatus),
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            }

            $connection->commit();
            $this->addFlash('success', 'La commande a été mise à jour.');
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return $this->redirectToRoute('app_admin_order_show', ['id' => $id]);
    }

    /** @return array<string, string> */
    private function getAvailableOrderStatuses(string $currentStatus): array
    {
        $allowedStatuses = array_unique([
            $currentStatus,
            ... (self::ORDER_STATUS_TRANSITIONS[$currentStatus] ?? []),
        ]);
        $labels = array_intersect_key(self::ORDER_STATUSES, array_flip($allowedStatuses));

        if (!isset($labels[$currentStatus])) {
            $labels[$currentStatus] = ucfirst($currentStatus);
        }

        return $labels;
    }

    #[Route(
        '/admin/suppliers/{id}/{action}',
        name: 'app_admin_supplier_review',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'action' => 'approve|reject'],
        methods: ['POST']
    )]
    public function reviewSupplier(
        string $id,
        string $action,
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response {
        $token = new CsrfToken(
            'supplier-review-' . $id . '-' . $action,
            (string) $request->request->get('_token', '')
        );
        if (!$csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedException('Jeton CSRF invalide.');
        }

        $admin = $this->getUser();
        if (!$admin instanceof DatabaseUser) {
            throw new AccessDeniedException('Un compte administrateur est requis.');
        }

        $approved = $action === 'approve';
        $verificationCondition = $approved
            ? 'AND EXISTS (
                 SELECT 1
                 FROM public."user" u
                 WHERE u.id = supplier_profile.user_id
                   AND u.is_email_verified IS TRUE
                   AND u.is_phone_verified IS TRUE
             )'
            : '';
        $updated = $connection->executeStatement(
            'UPDATE supplier_profile
             SET status = :status,
                 approved_at = :approved_at,
                 approved_by_id = :approved_by_id,
                 rejection_reason = :rejection_reason
             WHERE id = :id AND status = :pending_status ' . $verificationCondition,
            [
                'status' => $approved ? 'approved' : 'rejected',
                'approved_at' => $approved ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                'approved_by_id' => $approved ? $admin->getId() : null,
                'rejection_reason' => $approved ? null : 'Refusé par un administrateur.',
                'id' => $id,
                'pending_status' => 'pending',
            ]
        );

        if ($updated === 1) {
            $this->addFlash('success', $approved
                ? 'Le fournisseur a été approuvé.'
                : 'La candidature du fournisseur a été rejetée.');
        } else {
            $this->addFlash(
                'warning',
                $approved
                    ? 'Ce dossier a déjà été traité ou le compte doit encore vérifier son e-mail et son téléphone.'
                    : 'Ce dossier n’existe pas ou a déjà été traité.'
            );
        }

        return $this->redirectToRoute('app_admin_dashboard', ['tab' => 'suppliers_approval']);
    }

    #[Route('/admin/products/new', name: 'app_admin_product_new', methods: ['GET', 'POST'])]
    public function newProduct(
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrfTokenManager,
        SluggerInterface $slugger,
    ): Response {
        return $this->handleProductForm(null, $request, $connection, $csrfTokenManager, $slugger);
    }

    #[Route(
        '/admin/products/{id}/edit',
        name: 'app_admin_product_edit',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET', 'POST']
    )]
    public function editProduct(
        string $id,
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrfTokenManager,
        SluggerInterface $slugger,
    ): Response {
        return $this->handleProductForm($id, $request, $connection, $csrfTokenManager, $slugger);
    }

    #[Route(
        '/admin/products/{id}/archive',
        name: 'app_admin_product_archive',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST']
    )]
    public function archiveProduct(
        string $id,
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response {
        $token = new CsrfToken('product-archive-'.$id, (string) $request->request->get('_token', ''));
        if (!$csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedException('Jeton CSRF invalide.');
        }

        $updated = $connection->executeStatement(
            "UPDATE product SET status = 'archived', updated_at = NOW() WHERE id = :id AND status <> 'archived'",
            ['id' => $id],
        );

        $this->addFlash($updated === 1 ? 'success' : 'warning', $updated === 1
            ? 'Le produit a été archivé sans supprimer ses données.'
            : 'Produit introuvable ou déjà archivé.');

        return $this->redirectToRoute('app_admin_dashboard', ['tab' => 'products_table']);
    }

    private function handleProductForm(
        ?string $id,
        Request $request,
        Connection $connection,
        CsrfTokenManagerInterface $csrfTokenManager,
        SluggerInterface $slugger,
    ): Response {
        $product = null === $id ? null : $connection->fetchAssociative(
            'SELECT id, name, description, price, stock, status, supplier_id, category_id FROM product WHERE id = :id',
            ['id' => $id],
        );
        if (null !== $id && false === $product) {
            throw $this->createNotFoundException('Produit introuvable.');
        }

        $values = [
            'name' => $product['name'] ?? '',
            'description' => $product['description'] ?? '',
            'price' => $product['price'] ?? '',
            'stock' => $product['stock'] ?? 0,
            'status' => $product['status'] ?? 'draft',
            'supplier_id' => $product['supplier_id'] ?? '',
            'category_id' => $product['category_id'] ?? '',
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            $token = new CsrfToken('product-save-'.($id ?? 'new'), (string) $request->request->get('_token', ''));
            if (!$csrfTokenManager->isTokenValid($token)) {
                throw new AccessDeniedException('Jeton CSRF invalide.');
            }

            foreach (['name', 'description', 'price', 'stock', 'status', 'supplier_id', 'category_id'] as $field) {
                $values[$field] = trim((string) $request->request->get($field, ''));
            }

            if ('' === $values['name']) {
                $errors[] = 'Le nom du produit est obligatoire.';
            }
            if ('' === $values['description']) {
                $errors[] = 'La description du produit est obligatoire.';
            }

            $price = str_replace(',', '.', $values['price']);
            if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $price)) {
                $errors[] = 'Le prix doit être un nombre positif avec au maximum deux décimales.';
            }

            $stock = filter_var($values['stock'], FILTER_VALIDATE_INT);
            if (false === $stock || $stock < 0) {
                $errors[] = 'Le stock doit être un entier positif ou nul.';
            }

            if (!in_array($values['status'], ['active', 'draft', 'archived'], true)) {
                $errors[] = 'Le statut du produit est invalide.';
            }
            if (0 === (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM category WHERE id = :id',
                ['id' => $values['category_id']],
            )) {
                $errors[] = 'Choisis une catégorie existante.';
            }
            if (0 === (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM supplier_profile WHERE user_id = :id AND status = 'approved'",
                ['id' => $values['supplier_id']],
            )) {
                $errors[] = 'Choisis un fournisseur approuvé.';
            }

            if ([] === $errors) {
                $baseSlug = $slugger->slug($values['name'])->lower()->toString();
                $baseSlug = '' !== $baseSlug ? $baseSlug : 'produit';
                $slug = $baseSlug;
                $suffix = 2;
                do {
                    $slugQuery = 'SELECT COUNT(*) FROM product WHERE slug = :slug';
                    $slugParams = ['slug' => $slug];
                    if (null !== $id) {
                        $slugQuery .= ' AND id <> :id';
                        $slugParams['id'] = $id;
                    }
                    $slugExists = 0 < (int) $connection->fetchOne($slugQuery, $slugParams);
                    if ($slugExists) {
                        $slug = $baseSlug.'-'.$suffix++;
                    }
                } while ($slugExists);

                $productData = [
                    'name' => $values['name'],
                    'slug' => $slug,
                    'description' => $values['description'],
                    'price' => $price,
                    'stock' => $stock,
                    'status' => $values['status'],
                    'supplier_id' => $values['supplier_id'],
                    'category_id' => $values['category_id'],
                ];

                if (null === $id) {
                    $connection->insert('product', ['id' => $connection->fetchOne('SELECT gen_random_uuid()'), ...$productData]);
                    $this->addFlash('success', 'Le produit a été créé.');
                } else {
                    $connection->update('product', [...$productData, 'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id' => $id]);
                    $this->addFlash('success', 'Le produit a été mis à jour.');
                }

                return $this->redirectToRoute('app_admin_dashboard', ['tab' => 'products_table']);
            }
        }

        return $this->render('admin/product_form.html.twig', [
            'product' => $product,
            'values' => $values,
            'errors' => $errors,
            'categories' => $connection->fetchAllAssociative('SELECT id, name FROM category ORDER BY name'),
            'suppliers' => $connection->fetchAllAssociative(
                "SELECT u.id, sp.shop_name FROM supplier_profile sp
                 INNER JOIN public.\"user\" u ON u.id = sp.user_id
                 WHERE sp.status = 'approved' ORDER BY sp.shop_name"
            ),
        ]);
    }

    private function getExportDefinitions(): array
    {
        return [
            'clients' => [
                'Clients',
                ['ID', 'Nom complet', 'E-mail', 'Téléphone', 'E-mail vérifié', 'Téléphone vérifié', 'Rôles', 'Créé le'],
                "SELECT id, CONCAT(first_name, ' ', last_name) AS full_name, email, phone,
                        is_email_verified, is_phone_verified, roles::text AS roles, created_at
                 FROM public.\"user\"
                 WHERE NOT roles @> '[\"ROLE_ADMIN\"]'::jsonb
                   AND NOT EXISTS (SELECT 1 FROM supplier_profile sp WHERE sp.user_id = \"user\".id)
                 ORDER BY created_at DESC",
            ],
            'fournisseurs' => [
                'Fournisseurs',
                ['ID', 'Boutique', 'Contact', 'E-mail', 'Téléphone', 'Statut', 'Créé le'],
                "SELECT sp.id, sp.shop_name, CONCAT(u.first_name, ' ', u.last_name) AS contact_name,
                        u.email, u.phone, sp.status, sp.created_at
                 FROM supplier_profile sp
                 INNER JOIN public.\"user\" u ON u.id = sp.user_id
                 ORDER BY sp.created_at DESC",
            ],
            'administrateurs' => [
                'Administrateurs',
                ['ID', 'Nom complet', 'E-mail', 'Rôles', '2FA activée', 'Créé le'],
                "SELECT id, CONCAT(first_name, ' ', last_name) AS full_name, email,
                        roles::text AS roles, is_totp_enabled, created_at
                 FROM public.\"user\"
                 WHERE roles @> '[\"ROLE_ADMIN\"]'::jsonb
                 ORDER BY created_at DESC",
            ],
            'commandes' => [
                'Commandes',
                ['N° commande', 'Client', 'E-mail client', 'Montant', 'Paiement', 'Statut commande', 'Statut paiement', 'Créé le'],
                "SELECT o.order_number, CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
                        u.email, o.total, o.payment_method, o.status, o.payment_status, o.created_at
                 FROM orders o
                 LEFT JOIN public.\"user\" u ON u.id = o.client_id
                 ORDER BY o.created_at DESC",
            ],
            'produits' => [
                'Produits',
                ['ID', 'Produit', 'Slug', 'Description', 'Catégorie', 'Fournisseur', 'Prix', 'Stock', 'Statut', 'Créé le', 'Modifié le'],
                "SELECT p.id, p.name, p.slug, p.description, c.name AS category,
                        COALESCE(sp.shop_name, CONCAT(u.first_name, ' ', u.last_name)) AS supplier,
                        p.price, p.stock, p.status, p.created_at, p.updated_at
                 FROM product p
                 INNER JOIN category c ON c.id = p.category_id
                 LEFT JOIN public.\"user\" u ON u.id = p.supplier_id
                 LEFT JOIN supplier_profile sp ON sp.user_id = u.id
                 ORDER BY p.created_at DESC",
            ],
        ];
    }

    private static function normalizeExportCell(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'".$value : $value;
    }

    private function getExportDefinition(string $type): array
    {
        $definitions = $this->getExportDefinitions();

        if (!isset($definitions[$type])) {
            throw $this->createNotFoundException('Type d’export introuvable.');
        }

        return $definitions[$type];
    }

    #[Route('/admin/exports/{type}.csv', name: 'app_admin_export_csv', methods: ['GET'], requirements: ['type' => 'clients|fournisseurs|administrateurs|commandes|produits'])]
    public function exportCsv(Connection $connection, string $type): StreamedResponse
    {
        [, $headers, $sql] = $this->getExportDefinition($type);

        $response = new StreamedResponse(static function () use ($connection, $headers, $sql): void {
            $handle = fopen('php://output', 'wb');
            if (false === $handle) {
                throw new \RuntimeException('Impossible de créer le flux CSV.');
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers, ';');

            foreach ($connection->executeQuery($sql)->iterateAssociative() as $row) {
                $values = array_map(static fn (mixed $value): mixed => self::normalizeExportCell($value), array_values($row));
                fputcsv($handle, $values, ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="gobuy_%s.csv"', $type));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/admin/exports/workbook', name: 'app_admin_export', methods: ['GET'])]
    public function export(Connection $connection): StreamedResponse
    {
        $sheets = array_values($this->getExportDefinitions());

        $response = new StreamedResponse(static function () use ($connection, $sheets): void {
            $temporaryFile = tempnam(sys_get_temp_dir(), 'gobuy-export-');
            if (false === $temporaryFile) {
                throw new \RuntimeException('Impossible de créer le classeur temporaire.');
            }

            $writer = new Writer();
            try {
                $writer->openToFile($temporaryFile);

                foreach ($sheets as $index => [$name, $headers, $sql]) {
                    $sheet = 0 === $index
                        ? $writer->getCurrentSheet()
                        : $writer->addNewSheetAndMakeItCurrent();
                    $sheet->setName($name);
                    $writer->addRow(Row::fromValues($headers));

                    foreach ($connection->executeQuery($sql)->iterateAssociative() as $row) {
                        $values = array_map(static fn (mixed $value): mixed => self::normalizeExportCell($value), array_values($row));
                        $writer->addRow(Row::fromValues($values));
                    }
                }

                $writer->close();
                readfile($temporaryFile);
            } finally {
                if (isset($writer) && $writer instanceof Writer) {
                    $writer->close();
                }
                @unlink($temporaryFile);
            }
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="gobuy_export.xlsx"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
