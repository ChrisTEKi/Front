<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\VendorApplication;
use App\Entity\VendorPayout;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $users = $entityManager->getRepository(User::class)->findBy([], ['createdAt' => 'DESC']);
        $orders = $entityManager->getRepository(Order::class)->findBy([], ['createdAt' => 'DESC']);
        $products = $entityManager->getRepository(Product::class)->findBy([], ['createdAt' => 'DESC']);
        $vendorApplications = $entityManager->getRepository(VendorApplication::class)->findBy([], ['createdAt' => 'DESC']);
        $vendorPayouts = $entityManager->getRepository(VendorPayout::class)->findBy([], ['periodStart' => 'DESC']);
        $pendingSuppliers = $entityManager->getRepository(VendorApplication::class)->findBy([
            'type' => VendorApplication::TYPE_SUPPLIER,
            'status' => VendorApplication::STATUS_PENDING,
        ], ['createdAt' => 'DESC']);
        $approvedSuppliers = $entityManager->getRepository(VendorApplication::class)->count([
            'type' => VendorApplication::TYPE_SUPPLIER,
            'status' => VendorApplication::STATUS_APPROVED,
        ]);

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
            'vendorApplications' => $vendorApplications,
            'vendorPayouts' => $vendorPayouts,
            'pendingSuppliers' => $pendingSuppliers,
            'approvedSuppliers' => $approvedSuppliers,
            'payoutTotals' => $payoutTotals,
        ]);
    }
}
