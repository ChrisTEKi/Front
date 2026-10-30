<?php

namespace App\Http;

use App\Account\AccountActionException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Réponses JSON communes aux API de « Mon compte » (réglages, coordonnées, photo, adresses).
 *
 * Format :  { "ok": true, … }   ou   { "ok": false, "error": "<clé>", "message": "<texte traduit>" }
 * Le message vient du domaine de traduction « auth » (clé `error.<clé>`), dans la langue de la personne.
 * Toutes ces réponses sont liées à la session : jamais mises en cache (`no-store, private`).
 */
final class AccountResponder
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** Exécute une action et transforme un refus prévu (AccountActionException) en réponse JSON claire. */
    public function run(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (AccountActionException $e) {
            return $this->failure($e);
        }
    }

    /** @param array<string, mixed> $body */
    public function ok(array $body = [], int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->noStore(new JsonResponse(['ok' => true] + $body, $status));
    }

    public function failure(AccountActionException $e): JsonResponse
    {
        return $this->noStore(new JsonResponse([
            'ok' => false,
            'error' => $e->reason,
            'message' => $this->translator->trans('error.'.$e->reason, $e->params, 'auth'),
        ], match ($e->reason) {
            // 403 : confirmation refusée ou action interdite à ce compte.
            'step_up_failed', 'deletion_not_available', 'admin_two_factor_mandatory', 'addresses_not_available' => Response::HTTP_FORBIDDEN,
            // 404 : l'élément demandé n'existe pas (ou n'appartient pas à ce compte : même réponse).
            'not_found' => Response::HTTP_NOT_FOUND,
            // 413 / 415 : fichier trop gros / format non pris en charge.
            'photo_too_large' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            'photo_unsupported' => Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            // 429 : trop d'essais ou d'envois rapprochés.
            'step_up_locked', 'too_many_attempts', 'too_soon' => Response::HTTP_TOO_MANY_REQUESTS,
            // 409 : l'état actuel du compte ne permet pas l'action.
            'open_orders', 'no_password', 'contact_change_required', 'supplier_phone_required', 'address_limit' => Response::HTTP_CONFLICT,
            'delivery_failed' => Response::HTTP_SERVICE_UNAVAILABLE,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        }));
    }

    public function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
