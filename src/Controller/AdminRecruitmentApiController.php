<?php

namespace App\Controller;

use App\Admin\StepUpService;
use App\Entity\Interview;
use App\Entity\JobApplication;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Http\ModerationResponder;
use App\Http\ReadsJsonPayload;
use App\Recruitment\InterviewService;
use App\Recruitment\JobApplicationService;
use App\Recruitment\JobOfferService;
use App\Recruitment\RecruitmentException;
use App\Repository\JobApplicationRepository;
use App\Repository\JobOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * RECRUTEMENT : actions de l'administration (réponses JSON). Les pages sont dans AdminRecruitmentController.
 *
 * Accès : ROLE_ADMIN (pare-feu), réseau autorisé et session active (AdminGuardSubscriber), puis un DROIT FIN par action, jeton CSRF
 * exigé. Les actions qui effacent des données redemandent le mot de passe de l'administrateur (StepUpService).
 *
 *   POST /admin/api/recrutement/offres                       recruitment.manage  {title, department, location, contract, description, profile, closingDate}
 *   POST /admin/api/recrutement/offres/{id}                  recruitment.manage  mêmes champs (modification)
 *   POST /admin/api/recrutement/offres/{id}/statut           recruitment.manage  {action: publish|close}
 *   POST /admin/api/recrutement/offres/{id}/supprimer        recruitment.manage  {password}   (refusé s'il existe des candidatures)
 *   POST /admin/api/recrutement/candidats/{id}/statut        recruitment.decide  {action: review|refuse|hire, note}
 *   POST /admin/api/recrutement/candidats/{id}/supprimer     recruitment.decide  {password}
 *   POST /admin/api/recrutement/candidats/{id}/entretiens    recruitment.decide  {mode: phone|in_person, at, duration, location, note}
 *   POST /admin/api/recrutement/entretiens/{id}              recruitment.decide  {action: update|cancel|done, + champs de « entretiens » pour update}
 *
 * Quand un e-mail au candidat n'a pas pu partir (refus, convocation, annulation), l'action RÉUSSIT quand même et la réponse porte
 * « warning » : l'administrateur doit alors prévenir la personne lui-même.
 */
final class AdminRecruitmentApiController extends AbstractController
{
    use ReadsJsonPayload;

    private const ID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    private const OFFER_FIELDS = ['title', 'department', 'location', 'contract', 'description', 'profile', 'closingDate'];
    private const INTERVIEW_FIELDS = ['mode', 'at', 'duration', 'location', 'note'];

    public function __construct(
        private readonly JobOfferRepository $offers,
        private readonly JobApplicationRepository $applications,
        private readonly EntityManagerInterface $em,
        private readonly JobOfferService $offerService,
        private readonly JobApplicationService $applicationService,
        private readonly InterviewService $interviewService,
        private readonly ModerationResponder $responder,
        private readonly StepUpService $stepUp,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // ------------------------------------------------------------------ Offres

    #[Route('/admin/api/recrutement/offres', name: 'admin_api_job_offer_create', methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('recruitment.manage')]
    public function createOffer(Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($request): JsonResponse {
            $offer = $this->offerService->create($this->admin(), $this->fields($this->payload($request), self::OFFER_FIELDS));

            return $this->responder->ok(['redirect' => $this->generateUrl('app_admin_recruitment_offer', ['id' => $offer->getId()->toRfc4122()])], 201);
        });
    }

    #[Route('/admin/api/recrutement/offres/{id}', name: 'admin_api_job_offer_update', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('recruitment.manage')]
    public function updateOffer(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $this->offerService->update($this->admin(), $this->offer($id), $this->fields($this->payload($request), self::OFFER_FIELDS));

            return $this->responder->ok();
        });
    }

    #[Route('/admin/api/recrutement/offres/{id}/statut', name: 'admin_api_job_offer_status', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('recruitment.manage')]
    public function offerStatus(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $offer = $this->offer($id);
            match ($this->text($this->payload($request), 'action')) {
                'publish' => $this->offerService->publish($this->admin(), $offer),
                'close' => $this->offerService->close($this->admin(), $offer),
                default => throw new RecruitmentException('not_found'),
            };

            return $this->responder->ok();
        });
    }

    #[Route('/admin/api/recrutement/offres/{id}/supprimer', name: 'admin_api_job_offer_delete', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('recruitment.manage')]
    public function deleteOffer(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $admin = $this->admin();
            $this->stepUp->require($admin, $this->text($this->payload($request), 'password'));
            $this->offerService->delete($admin, $this->offer($id));

            return $this->responder->ok(['redirect' => $this->generateUrl('app_admin_recruitment')]);
        });
    }

    // ------------------------------------------------------------------ Candidats

    #[Route('/admin/api/recrutement/candidats/{id}/statut', name: 'admin_api_job_application_status', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('recruitment.decide')]
    public function applicationStatus(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $admin = $this->admin();
            $data = $this->payload($request);
            $application = $this->application($id);
            $body = [];
            switch ($this->text($data, 'action')) {
                case 'review':
                    $this->applicationService->startReview($admin, $application);
                    break;
                case 'hire':
                    $this->applicationService->markHired($admin, $application);
                    break;
                case 'refuse':
                    $body = $this->warn($this->applicationService->refuse($admin, $application, $this->text($data, 'note')));
                    break;
                default:
                    throw new RecruitmentException('not_found');
            }

            return $this->responder->ok($body);
        });
    }

    #[Route('/admin/api/recrutement/candidats/{id}/supprimer', name: 'admin_api_job_application_delete', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('recruitment.decide')]
    public function deleteApplication(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $admin = $this->admin();
            $this->stepUp->require($admin, $this->text($this->payload($request), 'password'));
            $application = $this->application($id);
            $offerId = $application->getOffer()->getId()->toRfc4122();
            $this->applicationService->delete($admin, $application);

            return $this->responder->ok(['redirect' => $this->generateUrl('app_admin_recruitment_offer', ['id' => $offerId])]);
        });
    }

    // ------------------------------------------------------------------ Entretiens

    #[Route('/admin/api/recrutement/candidats/{id}/entretiens', name: 'admin_api_job_interview_create', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('recruitment.decide')]
    public function scheduleInterview(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $result = $this->interviewService->schedule($this->admin(), $this->application($id), $this->fields($this->payload($request), self::INTERVIEW_FIELDS));

            return $this->responder->ok($this->warn($result['emailed']), 201);
        });
    }

    #[Route('/admin/api/recrutement/entretiens/{id}', name: 'admin_api_job_interview_update', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('recruitment.decide')]
    public function interview(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $admin = $this->admin();
            $data = $this->payload($request);
            $interview = $this->em->find(Interview::class, Uuid::fromString($id)) ?? throw new RecruitmentException('not_found');
            $body = [];
            switch ($this->text($data, 'action')) {
                case 'update':
                    $body = $this->warn($this->interviewService->update($admin, $interview, $this->fields($data, self::INTERVIEW_FIELDS))['emailed']);
                    break;
                case 'cancel':
                    $body = $this->warn($this->interviewService->cancel($admin, $interview));
                    break;
                case 'done':
                    $this->interviewService->markDone($admin, $interview);
                    break;
                default:
                    throw new RecruitmentException('not_found');
            }

            return $this->responder->ok($body);
        });
    }

    // ------------------------------------------------------------------ Outils

    /**
     * Les champs attendus, en texte (une valeur qui n'est pas un texte devient « »).
     *
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     *
     * @return array<string, string>
     */
    private function fields(array $data, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
        }

        return $out;
    }

    /** Message à afficher quand l'e-mail au candidat n'est pas parti (l'action elle-même a réussi). @return array<string, string> */
    private function warn(bool $emailed): array
    {
        return $emailed ? [] : ['warning' => $this->translator->trans('warning.email_not_sent', [], 'recruitment')];
    }

    private function offer(string $id): JobOffer
    {
        return $this->offers->findOneByUuid($id) ?? throw new RecruitmentException('not_found');
    }

    private function application(string $id): JobApplication
    {
        return $this->applications->findOneByUuid($id) ?? throw new RecruitmentException('not_found');
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
