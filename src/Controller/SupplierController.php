<?php

namespace App\Controller;

use App\Entity\User;
use App\Supplier\SupplierDashboardProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Espace Fournisseur : réservé aux comptes « fournisseur » (règle d'accès `^/fournisseur` → ROLE_SUPPLIER dans security.yaml).
 * Le contrôleur reste fin : les chiffres viennent de SupplierDashboardProvider (requêtes SQL sur les commandes du fournisseur connecté).
 */
final class SupplierController extends AbstractController
{
    #[Route('/fournisseur', name: 'app_supplier_dashboard', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, SupplierDashboardProvider $provider): Response
    {
        $response = $this->render('supplier/dashboard.html.twig', ['dashboard' => $provider->forSupplier($user)]);
        // Données propres à la personne connectée : jamais mises en cache (navigateur, proxy).
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
