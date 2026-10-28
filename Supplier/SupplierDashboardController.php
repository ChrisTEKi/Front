<?php

namespace App\Controller\Supplier;

use App\Form\SupplierProfileType;
use App\Repository\OrderItemRepository;
use App\Repository\ProductRepository;
use App\Service\SupplierStatsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

#[IsGranted('ROLE_SUPPLIER')]
#[Route('/fournisseur')]
final class SupplierDashboardController extends AbstractSupplierController
{
    #[Route('', name: 'app_supplier_dashboard', methods: ['GET'])]
    public function index(Request $request, SupplierStatsProvider $stats, ProductRepository $products): Response
    {
        $supplier = $this->supplier();

        return $this->render('supplier/dashboard.html.twig', [
            'supplier' => $supplier,
            'section' => 'dashboard',
            'stats' => $stats->overview($supplier, $request->query->getInt('jours', 30)),
            'periods' => SupplierStatsProvider::PERIODS,
            'productCounts' => $products->countByStatusForSupplier($supplier),
            'lowStock' => $products->findLowStock($supplier),
        ]);
    }

    #[Route('/clients', name: 'app_supplier_customers', methods: ['GET'])]
    public function customers(SupplierStatsProvider $stats): Response
    {
        $supplier = $this->supplier();

        return $this->render('supplier/customers.html.twig', [
            'supplier' => $supplier,
            'section' => 'customers',
            'customers' => $stats->customers($supplier),
        ]);
    }

    /** Customer list for the supplier's own CRM tools. No e-mail or phone: only what the marketplace may share. */
    #[Route('/clients/export.csv', name: 'app_supplier_customers_export', methods: ['GET'])]
    public function exportCustomers(OrderItemRepository $items, TranslatorInterface $translator): Response
    {
        $supplier = $this->supplier();
        $rows = $items->aggregateCustomers($supplier);

        $response = new StreamedResponse(function () use ($rows, $translator): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$translator->trans('Client'), $translator->trans('Commandes'), $translator->trans('Total dépensé (Ar)'), $translator->trans('Première commande'), $translator->trans('Dernière commande')], ';', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($out, [
                    $this->neutralise($row['name']),
                    $row['orders'],
                    $row['spent'],
                    $row['firstOrder']->format('Y-m-d'),
                    $row['lastOrder']->format('Y-m-d'),
                ], ';', '"', '\\');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="clients-'.$supplier->getSlug().'.csv"');

        return $response;
    }

    #[Route('/profil', name: 'app_supplier_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, EntityManagerInterface $em): Response
    {
        $supplier = $this->supplier();
        $form = $this->createForm(SupplierProfileType::class, $supplier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', t('Votre profil a été mis à jour.'));

            return $this->redirectToRoute('app_supplier_profile', status: 303);
        }

        return $this->render('supplier/profile.html.twig', [
            'supplier' => $supplier,
            'section' => 'profile',
            'form' => $form,
        ]);
    }

    /** Prevents spreadsheet formula injection from customer-controlled names. */
    private function neutralise(string $value): string
    {
        return '' !== $value && str_contains('=+-@', $value[0]) ? "'".$value : $value;
    }
}
