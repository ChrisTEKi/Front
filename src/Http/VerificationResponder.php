<?php

namespace App\Http;

use App\Auth\VerificationResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Convertit un VerificationResult (envoi/contrôle d'un code hors connexion) en réponse JSON traduite.
 *
 * Format : { "ok": true, "state": "sent"|"verified", "maskedTarget": "034 •• ••• 67", "resendIn": 28, … }
 *       ou { "ok": false, "error": "invalid_code", "message": "Code incorrect…", "attemptsLeft": 3, "retryAfter": 0, … }
 */
final class VerificationResponder
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function respond(VerificationResult $result): JsonResponse
    {
        $body = ['ok' => $result->ok, ...$result->data];
        if (null !== $result->state) {
            $body['state'] = $result->state;
        }
        if (null !== $result->maskedTarget) {
            $body['maskedTarget'] = $result->maskedTarget;
        }
        $body['resendIn'] = $result->resendIn;
        if (null !== $result->attemptsLeft) {
            $body['attemptsLeft'] = $result->attemptsLeft;
        }

        $status = Response::HTTP_OK;
        if (null !== $result->error) {
            $params = [
                '%seconds%' => max($result->retryAfter, $result->resendIn),
                '%minutes%' => (int) ceil($result->retryAfter / 60),
                '%attempts%' => $result->attemptsLeft ?? 0,
            ];
            $body['error'] = $result->error;
            $body['retryAfter'] = $result->retryAfter;
            // Message précis (ex. règle Mobile Money violée) si fourni, sinon message générique de l'erreur.
            $body['message'] = null !== $result->messageKey
                ? $this->translator->trans($result->messageKey, $params, 'auth')
                : $this->translator->trans('error.'.$result->error, $params, 'auth');
            $status = $this->statusFor($result->error);
        }

        $response = new JsonResponse($body, $status);
        if ($result->retryAfter > 0 && Response::HTTP_TOO_MANY_REQUESTS === $status) {
            $response->headers->set('Retry-After', (string) $result->retryAfter);
        }
        // Réponses liées à la session : jamais mises en cache.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function statusFor(string $error): int
    {
        return match ($error) {
            'too_many_attempts', 'too_soon' => Response::HTTP_TOO_MANY_REQUESTS,
            'delivery_failed' => Response::HTTP_SERVICE_UNAVAILABLE,
            'phone_taken', 'phone_change_forbidden', 'too_many_numbers', 'supplier_phone_required', 'contact_change_failed' => Response::HTTP_CONFLICT,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        };
    }
}
