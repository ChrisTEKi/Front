<?php

namespace App\Controller;

use App\Service\CategoryProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SupplierController extends AbstractController
{
    #[Route('/fournisseur', name: 'app_supplier_dashboard', methods: ['GET'])]
    public function index(CategoryProvider $categories): Response
    {
        // No back-end yet: everything below is an empty/zero state.
        // Swap for real Doctrine-backed data once the supplier's own
        // catalog, orders and payouts exist.
        return $this->render('supplier/dashboard.html.twig', [
            'categories' => $categories->all(),
            'myProducts' => [],
            'myOrders' => [],
            'payoutHistory' => [],
        ]);
    }
}
