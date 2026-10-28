<?php

namespace App\Controller\Supplier;

use App\Enum\OrderStatus;
use App\Repository\OrderItemRepository;
use App\Service\SupplierOrderManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Exception\TranslatableDomainException;

use function Symfony\Component\Translation\t;

#[IsGranted('ROLE_SUPPLIER')]
#[Route('/fournisseur/commandes')]
final class SupplierOrderController extends AbstractSupplierController
{
    #[Route('', name: 'app_supplier_orders', methods: ['GET'])]
    public function index(Request $request, OrderItemRepository $items): Response
    {
        $supplier = $this->supplier();
        $status = OrderStatus::tryFrom($request->query->getString('statut'));

        return $this->render('supplier/orders.html.twig', [
            'supplier' => $supplier,
            'section' => 'orders',
            'orders' => $items->findOrdersForSupplier($supplier, $status),
            'counts' => $items->countOrdersByStatus($supplier),
            'currentStatus' => $status,
            'statuses' => OrderStatus::cases(),
        ]);
    }

    #[Route('/{id}/statut', name: 'app_supplier_order_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changeStatus(int $id, Request $request, SupplierOrderManager $manager): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $next = OrderStatus::tryFrom($request->request->getString('status')) ?? throw $this->createNotFoundException();

        try {
            $manager->changeStatus($this->supplier(), $id, $next);
            $this->addFlash('success', t('Commande mise à jour : %status%.', ['%status%' => t($next->label())]));
        } catch (TranslatableDomainException $e) {
            $this->addFlash('error', $e->getTranslatable());
        }

        return $this->redirectToRoute('app_supplier_orders', status: 303);
    }
}
