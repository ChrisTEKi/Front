<?php

namespace App\Controller;

use App\Admin\StepUpService;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\SupplierAppealDocument;
use App\Entity\User;
use App\Enum\DeletionDelay;
use App\Enum\Permission;
use App\Enum\SupplierDocumentKind;
use App\Http\ModerationResponder;
use App\Http\ReadsJsonPayload;
use App\Moderation\AccountOverview;
use App\Moderation\ModerationException;
use App\Moderation\SupplierAppealService;
use App\Repository\SupplierAppealRepository;
use App\Repository\SupplierDocumentRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * FICHE D'UN COMPTE (client ou fournisseur) dans l'espace administrateur, et actions de modération sur ce compte.
 *
 * Accès : ROLE_ADMIN (pare-feu), réseau autorisé et session active (AdminGuardSubscriber), puis un DROIT FIN par action.
 * Le Super Admin les a tous ; il peut en donner à d'autres administrateurs (profils « PDG », « DG »…). Les actions destructrices
 * redemandent le mot de passe de l'administrateur (StepUpService). Les administrateurs ne se gèrent pas ici (Super Admin, onglet
 * « Administrateurs »).
 *
 *   GET  /admin/comptes/{id}                                  users.view (client) | suppliers.view (fournisseur)
 *   POST /admin/api/contestations/{id}/decision               suppliers.approve   {action: accept|dismiss, note}
 *   GET  /admin/contestations/{id}/pieces/{document}          suppliers.view      téléchargement d'une pièce jointe
 *   GET  /admin/fournisseurs/{id}/pieces/{kind}               suppliers.approve   pièce d'identité ou NIF/STAT du fournisseur (kind = identity|tax)
 */
final class AdminAccountController extends AbstractController
{
    use ReadsJsonPayload;

    private const ID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SupplierAppealRepository $appeals,
        private readonly SupplierAppealService $appealService,
        private readonly AccountOverview $overview,
        private readonly ModerationResponder $responder,
        private readonly StepUpService $stepUp,
        private readonly SupplierDocumentRepository $supplierDocuments,
        private readonly AuditLogger $audit,
    ) {
    }

    // ------------------------------------------------------------------ Page

    #[Route('/admin/comptes/{id}', name: 'app_admin_account', requirements: ['id' => self::ID], methods: ['GET'])]
    public function page(string $id): Response
    {
        $target = $this->target($id);
        $this->denyAccessUnlessGranted($target->isSupplier() ? Permission::SuppliersView->value : Permission::UsersView->value);

        $can = [];
        foreach (Permission::cases() as $permission) {
            $can[$permission->value] = $this->isGranted($permission->value);
        }

        $response = $this->render('admin/account.html.twig', [
            'a' => $this->overview->build($target),
            'can' => $can,
            'isSuperAdmin' => $this->admin()->isSuperAdmin(),
            'appealLimits' => ['note' => 500],
            'delays' => DeletionDelay::cases(),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    // ------------------------------------------------------------------ Contestations

    #[Route('/admin/api/contestations/{id}/decision', name: 'admin_api_appeal_decision', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('suppliers.approve')]
    public function decideAppeal(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $appeal = $this->appeals->findOneByUuid($id) ?? throw new ModerationException('not_found');
            $data = $this->payload($request);
            $this->appealService->decide($this->admin(), $appeal, $this->text($data, 'action'), $this->text($data, 'note'));

            return $this->responder->ok(['status' => $appeal->getStatus()->value]);
        });
    }

    /** Téléchargement d'une pièce jointe : toujours en « attachment » (jamais interprétée par le navigateur), type figé. */
    #[Route('/admin/contestations/{id}/pieces/{document}', name: 'app_admin_appeal_document', requirements: ['id' => self::ID, 'document' => self::ID], methods: ['GET'])]
    #[IsGranted('suppliers.view')]
    public function document(string $id, string $document): Response
    {
        $appeal = $this->appeals->findOneByUuid($id) ?? throw $this->createNotFoundException();
        $found = null;
        foreach ($appeal->getDocuments() as $candidate) {
            if ($candidate->getId()->toRfc4122() === strtolower($document)) {
                $found = $candidate;
            }
        }
        if (!$found instanceof SupplierAppealDocument) {
            throw $this->createNotFoundException();
        }

        $response = new Response($found->getContent(), Response::HTTP_OK, ['Content-Type' => $found->getContentType()]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $found->getFileName(), 'piece'));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        // Aucun script ni ressource ne peut s'exécuter depuis ce fichier, même ouvert par erreur dans le navigateur.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    /**
     * Pièce déposée par un fournisseur à l'inscription (pièce d'identité ou justificatif NIF / STAT). Même précautions que pour les
     * pièces de contestation (téléchargement « attachment », type figé, jamais interprétée ni mise en cache), et chaque ouverture est
     * inscrite au journal d'audit : ce sont des données personnelles. Réservé au droit de VALIDER les fournisseurs (suppliers.approve) :
     * voir la liste des fournisseurs (suppliers.view, ex. Finance, Compta) ne donne pas accès à leurs pièces d'identité.
     */
    #[Route('/admin/fournisseurs/{id}/pieces/{kind}', name: 'app_admin_supplier_document', requirements: ['id' => self::ID, 'kind' => 'identity|tax'], methods: ['GET'])]
    #[IsGranted('suppliers.approve')]
    public function supplierDocument(string $id, string $kind): Response
    {
        $supplier = $this->target($id);
        $document = $supplier->isSupplier() ? $this->supplierDocuments->findOneByOwnerAndKind($supplier, SupplierDocumentKind::from($kind)) : null;
        if (null === $document) {
            throw $this->createNotFoundException();
        }
        $this->audit->record(AuditAction::SUPPLIER_DOCUMENT_VIEWED, $this->admin(), 'user', $id, ['kind' => $kind]);

        $response = new Response($document->getContent(), Response::HTTP_OK, ['Content-Type' => $document->getContentType()]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $document->getFileName(), 'piece'));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        // Aucun script ni ressource ne peut s'exécuter depuis ce fichier, même ouvert par erreur dans le navigateur.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    // ------------------------------------------------------------------ Outils

    /** Compte ciblé : un client ou un fournisseur (jamais un administrateur : il se gère dans l'onglet réservé au Super Admin). */
    private function target(string $id): User
    {
        $user = Uuid::isValid($id) ? $this->users->findOneByUuid($id) : null;
        if (null === $user) {
            throw $this->createNotFoundException();
        }
        if ($user->isAdmin()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isAdmin()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
