<?php

namespace App\Controller;

use App\Entity\ApplicationDocument;
use App\Entity\JobApplication;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Enum\ApplicationStatus;
use App\Enum\ContractType;
use App\Enum\Permission;
use App\Recruitment\InterviewService;
use App\Recruitment\JobApplicationService;
use App\Recruitment\JobOfferService;
use App\Recruitment\RecruitmentTime;
use App\Repository\JobApplicationRepository;
use App\Repository\JobOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * RECRUTEMENT : pages de l'administration (offres, candidats, planning des entretiens) et lecture des pièces jointes.
 *
 * Accès : ROLE_ADMIN (pare-feu), réseau autorisé et session active (AdminGuardSubscriber), puis le droit « recruitment.view »
 * (lecture). Les actions elles-mêmes (créer, publier, refuser, convoquer…) sont dans AdminRecruitmentApiController, avec leurs droits.
 *
 *   GET /admin/recrutement                       les offres (nombre de candidats, nouveaux) + création d'une offre
 *   GET /admin/recrutement/offres/{id}           une offre (modifier, publier, clôturer, supprimer) et ses candidats (filtre d'état, tri, pages)
 *   GET /admin/recrutement/candidats/{id}        un candidat : coordonnées, message, CV / lettre, entretiens, décisions
 *   GET /admin/recrutement/planning              TOUS les entretiens, par candidat, classés par date de candidature (tri, filtres, pages)
 *   GET /admin/recrutement/pieces/{id}           téléchargement d'une pièce (CV, lettre) : en pièce jointe, jamais affichée dans la page
 *
 * Ouvrir la fiche d'un candidat « nouveau » le passe à « en cours d'examen » (action des droits de décision uniquement : un simple
 * lecteur ne modifie rien). Chaque lecture d'une pièce est inscrite au journal d'audit (données personnelles).
 */
final class AdminRecruitmentController extends AbstractController
{
    private const ID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly JobOfferRepository $offers,
        private readonly JobApplicationRepository $applications,
        private readonly JobApplicationService $applicationService,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin/recrutement', name: 'app_admin_recruitment', methods: ['GET'])]
    #[IsGranted('recruitment.view')]
    public function index(): Response
    {
        return $this->page('admin/recruitment/index.html.twig', [
            'rows' => $this->offers->findAllWithCounts(),
            'contracts' => ContractType::cases(),
            'offerLimits' => $this->offerLimits(),
        ]);
    }

    #[Route('/admin/recrutement/offres/{id}', name: 'app_admin_recruitment_offer', requirements: ['id' => self::ID], methods: ['GET'])]
    #[IsGranted('recruitment.view')]
    public function offer(string $id, Request $request): Response
    {
        $offer = $this->offers->findOneByUuid($id) ?? throw $this->createNotFoundException();
        $status = ApplicationStatus::tryFrom((string) $request->query->get('status', ''));
        $order = 'asc' === $request->query->get('order') ? 'asc' : 'desc';
        $pageNumber = max(1, $request->query->getInt('page', 1));
        $total = $this->applications->countForOffer($offer, $status);

        return $this->page('admin/recruitment/offer.html.twig', [
            'offer' => $offer,
            'contracts' => ContractType::cases(),
            'offerLimits' => $this->offerLimits(),
            'statuses' => ApplicationStatus::cases(),
            'status' => $status,
            'order' => $order,
            'items' => $this->applications->forOffer($offer, $status, $order, $pageNumber),
            'total' => $total,
            'all' => $this->applications->countForOfferAll($offer),
            'paging' => $this->paging($pageNumber, $total),
            'isOpen' => $offer->acceptsApplications($this->clock->now()),
        ]);
    }

    #[Route('/admin/recrutement/candidats/{id}', name: 'app_admin_recruitment_application', requirements: ['id' => self::ID], methods: ['GET'])]
    #[IsGranted('recruitment.view')]
    public function application(string $id): Response
    {
        $application = $this->applications->findOneByUuid($id) ?? throw $this->createNotFoundException();
        $admin = $this->getUser();
        // Seule une personne qui peut décider fait avancer l'état : un lecteur ne laisse aucune trace de décision.
        if ($admin instanceof User && $this->isGranted(Permission::RecruitmentDecide->value)) {
            $this->applicationService->startReview($admin, $application);
        }

        $interviews = $application->getInterviews()->toArray();
        usort($interviews, static fn ($a, $b): int => $a->getScheduledAt() <=> $b->getScheduledAt());

        return $this->page('admin/recruitment/application.html.twig', [
            'a' => $application,
            // « input » = valeur d'un champ datetime-local à l'heure de Madagascar (préremplit le formulaire de modification).
            'interviews' => array_map(static fn ($i): array => ['entity' => $i, 'input' => RecruitmentTime::toInput($i->getScheduledAt())], $interviews),
            'limits' => [
                'noteMax' => JobApplicationService::NOTE_MAX,
                'minMinutes' => InterviewService::MIN_MINUTES,
                'maxMinutes' => InterviewService::MAX_MINUTES,
                'defaultMinutes' => InterviewService::DEFAULT_MINUTES,
                'placeMax' => InterviewService::PLACE_MAX,
                'interviewNoteMax' => InterviewService::NOTE_MAX,
            ],
            'zone' => RecruitmentTime::ZONE,
        ]);
    }

    #[Route('/admin/recrutement/planning', name: 'app_admin_recruitment_planning', methods: ['GET'])]
    #[IsGranted('recruitment.view')]
    public function planning(Request $request): Response
    {
        $now = $this->clock->now();
        $order = 'desc' === $request->query->get('order') ? 'desc' : 'asc';
        $scope = 'all' === $request->query->get('scope') ? 'all' : 'upcoming';
        $offerId = (string) $request->query->get('offer', '');
        $offer = '' !== $offerId && Uuid::isValid($offerId) ? $this->offers->findOneByUuid($offerId) : null;
        $pageNumber = max(1, $request->query->getInt('page', 1));
        $total = $this->applications->countPlanning($scope, $offer, $now);

        return $this->page('admin/recruitment/planning.html.twig', [
            'items' => $this->applications->planning($order, $scope, $offer, $now, $pageNumber),
            'order' => $order,
            'scope' => $scope,
            'offer' => $offer,
            'offerChoices' => array_map(static fn (array $row): JobOffer => $row['offer'], $this->offers->findAllWithCounts()),
            'total' => $total,
            'paging' => $this->paging($pageNumber, $total),
            'zone' => RecruitmentTime::ZONE,
            'now' => $now,
        ]);
    }

    #[Route('/admin/recrutement/pieces/{id}', name: 'app_admin_recruitment_document', requirements: ['id' => self::ID], methods: ['GET'])]
    #[IsGranted('recruitment.view')]
    public function document(string $id): Response
    {
        $admin = $this->getUser();
        $document = $this->em->find(ApplicationDocument::class, Uuid::fromString($id));
        if (!$document instanceof ApplicationDocument || !$admin instanceof User) {
            throw $this->createNotFoundException();
        }
        $this->applicationService->recordDocumentView($admin, $document);

        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$document->getContentType()] ?? 'bin';
        $response = new Response($document->getContent());
        $response->headers->set('Content-Type', $document->getContentType());
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', $this->downloadName($document, $extension), 'document.'.$extension));
        // Une pièce déposée par un inconnu n'est JAMAIS interprétée par le navigateur (pas de détection de type, pas de page HTML) ni mise en cache.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    // ------------------------------------------------------------------ Outils

    /** @return array{titleMax: int, descriptionMin: int, descriptionMax: int, profileMax: int} */
    private function offerLimits(): array
    {
        return ['titleMax' => JobOfferService::TITLE_MAX, 'descriptionMin' => JobOfferService::DESCRIPTION_MIN, 'descriptionMax' => JobOfferService::DESCRIPTION_MAX, 'profileMax' => JobOfferService::PROFILE_MAX];
    }

    /** Nom du fichier téléchargé : « <type>-<candidat>.<ext> » sans caractère douteux (le nom d'origine n'est pas repris tel quel). */
    private function downloadName(ApplicationDocument $document, string $extension): string
    {
        $who = $document->getApplication()->getFullName();
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $who))), '-');

        return $document->getKind()->value.('' !== $slug ? '-'.$slug : '').'.'.$extension;
    }

    /** @return array{page: int, pages: int, total: int} */
    private function paging(int $page, int $total): array
    {
        $pages = max(1, (int) ceil($total / JobApplicationRepository::PER_PAGE));

        return ['page' => min($page, $pages), 'pages' => $pages, 'total' => $total];
    }

    /** @param array<string, mixed> $parameters */
    private function page(string $template, array $parameters): Response
    {
        $can = [];
        foreach (Permission::cases() as $permission) {
            $can[$permission->value] = $this->isGranted($permission->value);
        }
        $response = $this->render($template, $parameters + ['can' => $can]);
        // Données personnelles de candidats : ni mémoire cache du navigateur ni cache partagé.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
