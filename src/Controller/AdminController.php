<?php

namespace App\Controller;

use App\Admin\AdminDashboardProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tableau de bord administrateur (page HTML).
 *
 * L'accès exige ROLE_ADMIN (pare-feu), un réseau autorisé et une session active (AdminGuardSubscriber).
 * Chaque BLOC de la page est ensuite filtré par les droits fins de l'admin dans AdminDashboardProvider :
 * un admin « Compta » ne reçoit jamais la liste des clients s'il n'a pas le droit `users.view`.
 *
 * Les actions (approuver, suspendre, inviter…) sont dans AdminApiController (JSON).
 */
final class AdminController extends AbstractController
{
    /** Onglets connus : toute autre valeur de ?tab= retombe sur la vue d'ensemble. */
    private const TABS = ['overview', 'vendor_applications', 'vendor_payouts', 'suppliers_approval', 'users_table', 'orders_table', 'exports', 'admins', 'roles', 'audit'];

    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(Request $request, AdminDashboardProvider $provider): Response
    {
        $tab = (string) $request->query->get('tab', 'overview');
        $role = (string) $request->query->get('role', 'client');
        $search = $request->query->get('q');

        $data = $provider->build(
            \in_array($role, ['client', 'supplier', 'admin'], true) ? $role : 'client',
            \is_string($search) ? mb_substr($search, 0, 100) : null,
            $request->query->getInt('page', 1),
            $request->query->getInt('audit', 1),
        );

        $response = $this->render('admin/dashboard.html.twig', [
            'd' => $data,
            'tab' => \in_array($tab, self::TABS, true) ? $tab : 'overview',
        ]);
        // Données personnelles : jamais mises en cache (ni par le navigateur, ni par un proxy).
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
