<?php

namespace App\Controller;

use App\Auth\Dto\RegisterClientData;
use App\Auth\Dto\RegisterSupplierData;
use App\Auth\FlowOutcome;
use App\Auth\LoginFlow;
use App\Auth\PasswordResetService;
use App\Auth\RegistrationService;
use App\Http\ReadsJsonPayload;
use App\Http\ValidationErrors;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * API JSON du parcours de connexion/inscription, appelée par le JavaScript de l'interface (assets/app.js → initAuth).
 *
 * Toutes les routes POST exigent le jeton CSRF (en-tête X-CSRF-Token, voir ApiCsrfSubscriber).
 * Le contrôleur reste FIN : il lit la requête, appelle un service, convertit le résultat en JSON.
 * La logique (et la sécurité) est dans LoginFlow, RegistrationService, PasswordResetService, OtpService.
 *
 * Format des réponses :
 *   { "ok": true|false, "step": "verify_email", "error": "invalid_code"|null, "message": "texte traduit",
 *     "maskedTarget": "j•••@gmail.com", "resendIn": 28, "attemptsLeft": 3, "redirect": "/", "errors": {champ: [messages]} }
 */
#[Route('/auth', name: 'auth_', defaults: ['_csrf_token_id' => 'auth'])]
final class AuthApiController extends AbstractController
{
    use ReadsJsonPayload;

    public function __construct(
        private readonly LoginFlow $flow,
        private readonly RegistrationService $registration,
        private readonly PasswordResetService $passwordReset,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** Où en est le parcours en cours (appelé au chargement de la page de connexion). */
    #[Route('/state', name: 'state', methods: ['GET'])]
    public function state(): JsonResponse
    {
        return $this->respond($this->flow->resume());
    }

    /** Étape 1 : e-mail + mot de passe. */
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        return $this->respond($this->flow->attemptPassword($this->text($data, 'email'), (string) ($data['password'] ?? '')));
    }

    /** Inscription d'un client. */
    #[Route('/register/client', name: 'register_client', methods: ['POST'])]
    public function registerClient(Request $request): JsonResponse
    {
        $dto = RegisterClientData::fromArray($this->payload($request));
        if (null !== $invalid = $this->validationFailure($dto)) {
            return $invalid;
        }

        return $this->respond($this->registration->registerClient($dto, $request->getLocale()));
    }

    /** Inscription d'un fournisseur (e-mail + téléphone à vérifier, puis approbation par un administrateur). */
    #[Route('/register/supplier', name: 'register_supplier', methods: ['POST'])]
    public function registerSupplier(Request $request): JsonResponse
    {
        $dto = RegisterSupplierData::fromArray($this->payload($request));
        if (null !== $invalid = $this->validationFailure($dto)) {
            return $invalid;
        }

        return $this->respond($this->registration->registerSupplier($dto, $request->getLocale()));
    }

    /** Vérifie le code saisi pour l'étape en cours (l'étape est connue du SERVEUR, pas envoyée par le navigateur). */
    #[Route('/verify', name: 'verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        return $this->respond($this->flow->verifyCode($this->text($this->payload($request), 'code')));
    }

    /** Renvoie un code (et, pour la 2FA, permet de choisir le canal « email » ou « sms »). */
    #[Route('/resend', name: 'resend', methods: ['POST'])]
    public function resend(Request $request): JsonResponse
    {
        $channel = $this->text($this->payload($request), 'channel');

        return $this->respond($this->flow->resend('' !== $channel ? $channel : null));
    }

    /** « Plus tard » sur la vérification facultative du téléphone. */
    #[Route('/skip', name: 'skip', methods: ['POST'])]
    public function skip(): JsonResponse
    {
        return $this->respond($this->flow->skip());
    }

    /** Confirme la restauration d'un compte en cours de suppression. */
    #[Route('/restore', name: 'restore', methods: ['POST'])]
    public function restore(): JsonResponse
    {
        return $this->respond($this->flow->confirmRestore());
    }

    /** Abandonne le parcours en cours. */
    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(): JsonResponse
    {
        $this->flow->cancel();

        return $this->noStore(new JsonResponse(['ok' => true, 'step' => 'login']));
    }

    /** Mot de passe oublié, étape 1 : envoi du code. Réponse identique que le compte existe ou non. */
    #[Route('/forgot', name: 'forgot', methods: ['POST'])]
    public function forgot(Request $request): JsonResponse
    {
        $retryAfter = $this->passwordReset->requestReset($this->text($this->payload($request), 'email'));
        if ($retryAfter > 0) {
            return $this->failure('too_many_attempts', Response::HTTP_TOO_MANY_REQUESTS, ['%seconds%' => $retryAfter, '%minutes%' => (int) ceil($retryAfter / 60)], $retryAfter);
        }

        return $this->noStore(new JsonResponse([
            'ok' => true,
            'message' => $this->translator->trans('forgot.sent', [], 'auth'),
        ]));
    }

    /** Mot de passe oublié, étape 2 : code + nouveau mot de passe. */
    #[Route('/reset', name: 'reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        $data = $this->payload($request);
        $outcome = $this->passwordReset->reset($this->text($data, 'email'), $this->text($data, 'code'), (string) ($data['password'] ?? ''));

        if ($outcome->ok) {
            return $this->noStore(new JsonResponse([
                'ok' => true,
                'step' => 'login',
                'message' => $this->translator->trans('reset.done', [], 'auth'),
            ]));
        }
        if ([] !== $outcome->fieldErrors) {
            return $this->noStore(new JsonResponse(['ok' => false, 'error' => 'validation', 'message' => $this->translator->trans('error.validation', [], 'auth'), 'errors' => $outcome->fieldErrors], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->failure((string) $outcome->error, 'too_many_attempts' === $outcome->error ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY, ['%seconds%' => $outcome->retryAfter, '%minutes%' => (int) ceil($outcome->retryAfter / 60)], $outcome->retryAfter);
    }

    // ------------------------------------------------------------------ Outils

    /** Valide un DTO ; retourne la réponse 422 (avec les erreurs par champ) ou null si tout est valide. */
    private function validationFailure(object $dto): ?JsonResponse
    {
        $violations = $this->validator->validate($dto);
        if (0 === \count($violations)) {
            return null;
        }

        return $this->noStore(new JsonResponse([
            'ok' => false,
            'error' => 'validation',
            'message' => $this->translator->trans('error.validation', [], 'auth'),
            'errors' => ValidationErrors::toArray($violations),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Convertit un résultat de LoginFlow en réponse JSON (message traduit + code HTTP adapté). */
    private function respond(FlowOutcome $outcome): JsonResponse
    {
        $data = $outcome->toArray();
        $status = Response::HTTP_OK;

        if (null !== $outcome->error) {
            $data['message'] = $this->translator->trans('error.'.$outcome->error, [
                '%seconds%' => max($outcome->retryAfter, $outcome->resendIn),
                '%minutes%' => (int) ceil($outcome->retryAfter / 60),
                '%attempts%' => $outcome->attemptsLeft ?? 0,
            ], 'auth');
            $status = $this->statusFor($outcome->error);
        }

        $response = new JsonResponse($data, $status);
        $retry = max($outcome->retryAfter, 'too_soon' === $outcome->error ? $outcome->resendIn : 0);
        if ($retry > 0 && Response::HTTP_TOO_MANY_REQUESTS === $status) {
            $response->headers->set('Retry-After', (string) $retry);
        }

        return $this->noStore($response);
    }

    /**
     * @param array<string, scalar> $params
     */
    private function failure(string $error, int $status, array $params = [], int $retryAfter = 0): JsonResponse
    {
        $response = new JsonResponse([
            'ok' => false,
            'error' => $error,
            'message' => $this->translator->trans('error.'.$error, $params, 'auth'),
            'retryAfter' => $retryAfter,
        ], $status);
        if ($retryAfter > 0) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $this->noStore($response);
    }

    private function statusFor(string $error): int
    {
        return match ($error) {
            'invalid_credentials', 'session_expired' => Response::HTTP_UNAUTHORIZED,
            'account_suspended', 'network_not_allowed' => Response::HTTP_FORBIDDEN,
            'too_many_attempts', 'too_soon' => Response::HTTP_TOO_MANY_REQUESTS,
            'delivery_failed' => Response::HTTP_SERVICE_UNAVAILABLE,
            'phone_taken', 'cannot_skip', 'unexpected_step' => Response::HTTP_CONFLICT,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        };
    }

    /** Ces réponses contiennent des informations de session : jamais mises en cache (navigateur, proxy). */
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
