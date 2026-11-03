<?php

namespace App\Controller;

use App\Entity\SupplierAppeal;
use App\Entity\User;
use App\Http\ModerationResponder;
use App\Moderation\ModerationException;
use App\Moderation\SupplierAppealService;
use App\Repository\SupplierAppealRepository;
use App\Repository\SupplierProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Contestation d'une décision sur SON dossier (espace fournisseur : `^/fournisseur` exige ROLE_SUPPLIER).
 *
 *   GET  /fournisseur/contestation       page : décision en cours, historique, formulaire (message + pièces)
 *   POST /fournisseur/api/contestation   dépôt (multipart : « message » + « files[] »), jeton CSRF exigé
 *
 * Le fournisseur ne voit et ne dépose QUE pour son propre compte : aucune route ne reçoit d'identifiant de fournisseur.
 */
final class SupplierAppealController extends AbstractController
{
    public function __construct(
        private readonly SupplierAppealService $service,
        private readonly SupplierAppealRepository $appeals,
        private readonly SupplierProfileRepository $profiles,
        private readonly ModerationResponder $responder,
    ) {
    }

    #[Route('/fournisseur/contestation', name: 'app_supplier_appeal', methods: ['GET'])]
    public function page(#[CurrentUser] User $user): Response
    {
        $profile = $this->profiles->findOneByUser($user);
        $response = $this->render('supplier/appeal.html.twig', [
            'profile' => $profile,
            'refusal' => $this->service->refusalFor($user, $profile),
            'appeals' => array_map(static fn (SupplierAppeal $a): array => [
                'status' => $a->getStatus()->value,
                'contested' => $a->getContestedStatus(),
                'message' => $a->getMessage(),
                'createdAt' => $a->getCreatedAt(),
                'decidedAt' => $a->getDecidedAt(),
                'note' => $a->getDecisionNote(),
                'files' => array_map(static fn ($d): array => ['name' => $d->getFileName(), 'size' => $d->getSize()], $a->getDocuments()->toArray()),
            ], $this->appeals->findForSupplier($user)),
            'limits' => [
                'files' => SupplierAppealService::MAX_FILES,
                'fileMb' => (int) (SupplierAppealService::MAX_FILE_BYTES / 1048576),
                'totalMb' => (int) (SupplierAppealService::MAX_TOTAL_BYTES / 1048576),
                'min' => SupplierAppealService::MESSAGE_MIN,
                'max' => SupplierAppealService::MESSAGE_MAX,
                'perDecision' => SupplierAppealService::MAX_PER_DECISION,
            ],
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/fournisseur/api/contestation', name: 'supplier_api_appeal', methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    public function submit(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($user, $request): JsonResponse {
            $raw = $request->files->get('files', []);
            $files = array_values(array_filter(\is_array($raw) ? $raw : [$raw], static fn (mixed $f): bool => $f instanceof UploadedFile));
            // Un envoi trop gros pour PHP (post_max_size) arrive sans aucun champ : message d'erreur clair plutôt que « message trop court ».
            if ([] === $request->request->all() && (int) $request->headers->get('Content-Length', '0') > 0) {
                throw new ModerationException('appeal_files_total', ['%mb%' => (int) (SupplierAppealService::MAX_TOTAL_BYTES / 1048576)]);
            }
            $this->service->submit($user, (string) $request->request->get('message', ''), $files);

            return $this->responder->ok([], Response::HTTP_CREATED);
        });
    }
}
