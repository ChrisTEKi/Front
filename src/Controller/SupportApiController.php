<?php

namespace App\Controller;

use App\Contact\SupportChannel;
use App\Contact\SupportMessageData;
use App\Contact\SupportMessageMailer;
use App\Entity\User;
use App\Http\ReadsJsonPayload;
use App\Http\ValidationErrors;
use App\Notification\MailDeliveryException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * API JSON des formulaires du « Centre d'information » et du tiroir « Feedback » : contact, réclamation, candidature, avis.
 *
 *   POST /support/message   {kind, name, contact, order, reason, mood, message}
 *
 * Ouverte aux visiteurs non connectés (on doit pouvoir écrire au support sans compte), donc protégée par :
 *   - le jeton CSRF (en-tête X-CSRF-Token, voir ApiCsrfSubscriber),
 *   - une limite de 5 envois / heure / adresse IP (anti-spam),
 *   - une validation stricte (SupportMessageData) et un e-mail en texte brut (aucune injection possible).
 * Le message part par e-mail vers GOBUY_SUPPORT_EMAIL ; sans adresse configurée, la route répond 503 et le site masque les formulaires.
 */
#[Route('/support', name: 'support_', defaults: ['_csrf_token_id' => 'auth'])]
final class SupportApiController extends AbstractController
{
    use ReadsJsonPayload;

    public function __construct(
        private readonly SupportChannel $channel,
        private readonly SupportMessageMailer $mailer,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $supportMessageIpLimiter,
    ) {
    }

    #[Route('/message', name: 'message', methods: ['POST'])]
    public function message(Request $request): JsonResponse
    {
        if (!$this->channel->isEnabled()) {
            return $this->failure('unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Limite par adresse IP : on consomme un « jeton » à chaque tentative, valide ou non (empêche de tester en rafale).
        $limit = $this->supportMessageIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->failure('rate_limited', Response::HTTP_TOO_MANY_REQUESTS, $retryAfter);
        }

        $data = SupportMessageData::fromArray($this->payload($request));
        $violations = $this->validator->validate($data);
        if (\count($violations) > 0) {
            return $this->noStore(new JsonResponse([
                'ok' => false,
                'error' => 'validation',
                'message' => $this->translator->trans('support.error.validation'),
                'errors' => ValidationErrors::toArray($violations),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $user = $this->getUser();
        try {
            $this->mailer->send($data, $request->getLocale(), $user instanceof User ? $user : null);
        } catch (MailDeliveryException) {
            return $this->failure('send_failed', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->noStore(new JsonResponse(['ok' => true, 'message' => $this->translator->trans('support.sent.'.$data->kind)]));
    }

    private function failure(string $error, int $status, int $retryAfter = 0): JsonResponse
    {
        $response = new JsonResponse([
            'ok' => false,
            'error' => $error,
            'message' => $this->translator->trans('support.error.'.$error, ['%minutes%' => (int) ceil($retryAfter / 60)]),
            'retryAfter' => $retryAfter,
        ], $status);
        if ($retryAfter > 0) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $this->noStore($response);
    }

    /** Les réponses de l'API ne doivent jamais être gardées en cache. */
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
