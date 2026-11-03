<?php

namespace App\Http;

use App\Admin\AdminActionException;
use App\Catalog\CatalogException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Réponses JSON communes aux API du CATALOGUE (espace fournisseur et espace administrateur).
 *
 * Format :  { "ok": true, … }   ou   { "ok": false, "error": "<clé>", "message": "<texte traduit>" }
 * Un refus prévu du catalogue (CatalogException) est traduit dans le domaine « catalog » ; une confirmation de mot de passe
 * refusée (AdminActionException, voir StepUpService) dans le domaine « admin ». Réponses jamais mises en cache.
 */
final class CatalogResponder
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** Exécute une action et transforme un refus prévu en réponse JSON claire. */
    public function run(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CatalogException $e) {
            return $this->failure($e->reason, $this->translator->trans('error.'.$e->reason, $e->params, 'catalog'));
        } catch (AdminActionException $e) {
            return $this->failure($e->reason, $this->translator->trans('error.'.$e->reason, $e->params, 'admin'));
        }
    }

    /** @param array<string, mixed> $body */
    public function ok(array $body = [], int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->noStore(new JsonResponse(['ok' => true] + $body, $status));
    }

    /** @param array<string, list<string>> $errors messages par champ (validation) */
    public function invalid(array $errors, string $message): JsonResponse
    {
        return $this->noStore(new JsonResponse(['ok' => false, 'error' => 'validation', 'message' => $message, 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function failure(string $reason, string $message): JsonResponse
    {
        return $this->noStore(new JsonResponse(['ok' => false, 'error' => $reason, 'message' => $message], match ($reason) {
            'not_found' => Response::HTTP_NOT_FOUND,
            'supplier_not_active', 'step_up_failed', 'forbidden', 'gobuy_only' => Response::HTTP_FORBIDDEN,
            'step_up_locked' => Response::HTTP_TOO_MANY_REQUESTS,
            'product_limit', 'product_removed', 'already_removed', 'not_removed', 'product_not_active' => Response::HTTP_CONFLICT,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        }));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
