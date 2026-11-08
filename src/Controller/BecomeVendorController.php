<?php

namespace App\Controller;

use App\Supplier\CommissionSchedule;
use App\Supplier\SupplierDocuments;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique « Devenir partenaire » : présentation, simulateur de commissions et assistant de candidature.
 * Le barème de commission vient de CommissionSchedule (même source que l'Espace Fournisseur).
 */
final class BecomeVendorController extends AbstractController
{
    #[Route('/devenir-partenaire', name: 'app_become_vendor', methods: ['GET'])]
    public function index(CommissionSchedule $commissions): Response
    {
        return $this->render('vendor/become.html.twig', [
            'commissionRates' => $commissions->all(),
            // Taille maximale d'une pièce, rappelée dans l'assistant (le contrôle réel est fait par le serveur).
            'documentMaxMb' => (int) (SupplierDocuments::MAX_FILE_BYTES / 1048576),
        ]);
    }
}
