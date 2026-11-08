<?php

namespace App\Controller;

use App\Entity\JobOffer;
use App\Http\ModerationResponder;
use App\Recruitment\JobApplicationService;
use App\Recruitment\RecruitmentException;
use App\Repository\JobOfferRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Offres d'emploi PUBLIQUES et dépôt de candidature (aucun compte nécessaire).
 *
 *   GET  /emplois                          liste des offres ouvertes
 *   GET  /emplois/{id}                     une offre + le formulaire de candidature (404 si l'offre n'est pas ouverte)
 *   POST /emplois/{id}/candidater          dépôt (multipart : name, email, phone, message, cv, letter), jeton CSRF exigé
 *
 * Aucun identifiant de candidature n'est jamais donné au visiteur : il ne peut ni relire ni modifier son dépôt (il écrit au support).
 * Un champ-piège « website » (invisible pour une personne) fait croire à un succès à un robot qui le remplit, sans rien enregistrer.
 */
final class JobController extends AbstractController
{
    private const ID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly JobOfferRepository $offers,
        private readonly JobApplicationService $applications,
        private readonly ModerationResponder $responder,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/emplois', name: 'app_jobs', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('jobs/index.html.twig', ['offers' => $this->offers->findPublic($this->clock->now())]);
    }

    #[Route('/emplois/{id}', name: 'app_job', requirements: ['id' => self::ID], methods: ['GET'])]
    public function show(string $id): Response
    {
        return $this->render('jobs/show.html.twig', [
            'offer' => $this->publicOffer($id),
            'limits' => [
                'fileMb' => (int) (JobApplicationService::MAX_FILE_BYTES / 1048576),
                'totalMb' => (int) (JobApplicationService::MAX_TOTAL_BYTES / 1048576),
                'messageMax' => JobApplicationService::MESSAGE_MAX,
            ],
        ]);
    }

    #[Route('/emplois/{id}/candidater', name: 'app_job_apply', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    public function apply(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            // Un envoi plus gros que post_max_size arrive sans aucun champ : message clair plutôt que « nom invalide ».
            if ([] === $request->request->all() && (int) $request->headers->get('Content-Length', '0') > 0) {
                throw new RecruitmentException('job_files_total', ['%mb%' => (int) (JobApplicationService::MAX_TOTAL_BYTES / 1048576)]);
            }
            $offer = $this->offers->findOneByUuid($id) ?? throw new RecruitmentException('not_found');

            // Piège à robots : le champ « website » reste vide pour une vraie personne. Réponse identique à un succès, rien n'est enregistré.
            if ('' !== trim((string) $request->request->get('website', ''))) {
                return $this->responder->ok([], Response::HTTP_CREATED);
            }

            $field = static fn (string $key): string => \is_scalar($request->request->all()[$key] ?? null) ? (string) $request->request->all()[$key] : '';
            $this->applications->apply(
                $offer,
                ['name' => $field('name'), 'email' => $field('email'), 'phone' => $field('phone'), 'message' => $field('message')],
                $this->uploaded($request, 'cv'),
                $this->uploaded($request, 'letter'),
                $request->getLocale(),
                $request->getClientIp() ?? 'unknown',
            );

            return $this->responder->ok([], Response::HTTP_CREATED);
        });
    }

    private function publicOffer(string $id): JobOffer
    {
        $offer = $this->offers->findOneByUuid($id);
        if (null === $offer || !$offer->acceptsApplications($this->clock->now())) {
            throw $this->createNotFoundException();
        }

        return $offer;
    }

    private function uploaded(Request $request, string $key): ?UploadedFile
    {
        $file = $request->files->get($key);

        return $file instanceof UploadedFile ? $file : null;
    }
}
