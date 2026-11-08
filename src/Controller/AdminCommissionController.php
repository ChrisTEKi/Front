<?php

namespace App\Controller;

use App\Admin\StepUpService;
use App\Audit\AuditAction;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Http\ModerationResponder;
use App\Http\ReadsJsonPayload;
use App\Supplier\CommissionSchedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * TAUX DE COMMISSION par rayon (écran « Commissions » de l'administration).
 *
 * Accès : ROLE_ADMIN (pare-feu), réseau autorisé et session active (AdminGuardSubscriber), puis le droit « commissions.manage ».
 *
 *   GET  /admin/commissions        les taux en vigueur, qui les a changés et quand, les dernières modifications
 *   POST /admin/api/commissions    {"cat-mode": "7,5", "cat-elec": "5", …, password}   enregistre les taux envoyés
 *
 * Changer un taux touche ce que voient les fournisseurs et les futurs partenaires : l'enregistrement redemande le mot de passe de
 * l'administrateur. Les règles (nombre, 2 décimales, 0 à 50 %) sont vérifiées par le SERVEUR (CommissionSchedule::update) ; la page
 * ne fait que les rappeler. Chaque modification est écrite au journal d'audit (ancien → nouveau taux), sans mot de passe.
 */
final class AdminCommissionController extends AbstractController
{
    use ReadsJsonPayload;

    private const HISTORY = 15;

    public function __construct(
        private readonly CommissionSchedule $commissions,
        private readonly ModerationResponder $responder,
        private readonly StepUpService $stepUp,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/commissions', name: 'app_admin_commissions', methods: ['GET'])]
    #[IsGranted('commissions.manage')]
    public function page(): Response
    {
        $history = [];
        foreach ($this->em->getRepository(AuditLog::class)->findBy(['action' => AuditAction::COMMISSION_CHANGED], ['createdAt' => 'DESC'], self::HISTORY) as $log) {
            $changes = $log->getMetadata()['changes'] ?? [];
            $history[] = ['at' => $log->getCreatedAt(), 'by' => $log->getActorEmail(), 'changes' => \is_array($changes) ? $changes : []];
        }

        $response = $this->render('admin/commissions.html.twig', [
            'rows' => $this->commissions->all(),
            'max' => CommissionSchedule::MAX_RATE,
            'history' => $history,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/admin/api/commissions', name: 'admin_api_commissions_save', methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('commissions.manage')]
    public function save(Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($request): JsonResponse {
            $admin = $this->getUser();
            if (!$admin instanceof User || !$admin->isAdmin()) {
                throw $this->createAccessDeniedException();
            }
            $data = $this->payload($request);
            $this->stepUp->require($admin, $this->text($data, 'password'));

            $changes = $this->commissions->update($admin, $data);

            return $this->responder->ok(['changed' => \count($changes)]);
        });
    }
}
