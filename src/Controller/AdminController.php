<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        // No back-end yet: every collection below is empty. Swap for real
        // Doctrine repositories (User, Order, VendorApplication, VendorPayout)
        // once Symfony Security + the database are wired up.
        return $this->render('admin/dashboard.html.twig', [
            'users' => [],
            'orders' => [],
            'products' => [],
            'vendorApplications' => [],
            'vendorPayouts' => [],
            'pendingSuppliers' => [],
        ]);
    }
}
