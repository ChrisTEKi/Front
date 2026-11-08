<?php

namespace App\Http;

use App\Admin\AdminActionException;
use App\Catalog\CatalogException;
use App\Moderation\ModerationException;
use App\Recruitment\RecruitmentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Réponses JSON communes aux API de MODÉRATION (contestations, sanctions, liste noire, suppressions programmées).
 *
 * Format :  { "ok": true, … }   ou   { "ok": false, "error": "<clé>", "message": "<texte traduit>" }
 * Un refus prévu de ce domaine (ModerationException) est traduit dans le domaine « sanctions » ; une confirmation de mot de
 * passe refusée ou une règle d'administration (AdminActionException) dans le domaine « admin » ; un refus du catalogue
 * (CatalogException, ex. retrait de produits) dans « catalog », un refus du recrutement (RecruitmentException) dans « recruitment ».
 * Réponses jamais mises en cache.
 */
final class ModerationResponder
{
    /** Refus qui signifient « interdit à ce compte » (droit, mot de passe de confirmation, règle de protection). */
    private const FORBIDDEN = ['forbidden', 'step_up_failed', 'only_super_admin', 'cannot_target_admin', 'cannot_target_self', 'cannot_modify_super_admin', 'cannot_suspend_self', 'use_admin_management', 'gobuy_only', 'supplier_not_active'];

    /** Refus qui signifient « l'état actuel des données ne le permet pas ». */
    private const CONFLICT = [
        'appeal_open', 'appeal_limit', 'appeal_closed', 'appeal_not_needed', 'appeal_not_allowed',
        'already_banned', 'not_banned', 'already_frozen', 'not_frozen', 'account_not_active', 'already_blacklisted',
        'deletion_already_scheduled', 'no_deletion_scheduled', 'open_orders', 'invalid_transition', 'supplier_not_verified',
        'product_removed', 'already_removed', 'not_removed', 'product_not_active', 'nothing_to_refund', 'not_a_supplier',
        // Recrutement
        'job_offer_closed', 'job_already_applied', 'job_offer_has_applications', 'job_offer_already_open', 'job_offer_not_open',
        'job_application_final', 'job_already_refused', 'job_interview_overlap', 'job_interview_not_scheduled',
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** Exécute une action et transforme un refus prévu en réponse JSON claire. */
    public function run(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ModerationException $e) {
            return $this->failure($e->reason, $this->translator->trans('error.'.$e->reason, $e->params, 'sanctions'));
        } catch (AdminActionException $e) {
            return $this->failure($e->reason, $this->translator->trans('error.'.$e->reason, $e->params, 'admin'));
        } catch (CatalogException $e) {
            return $this->failure($e->reason, $this->translator->trans('error.'.$e->reason, $e->params, 'catalog'));
        } catch (RecruitmentException $e) {
            return $this->failure($e->reason, $this->translator->trans('error.'.$e->reason, $e->params, 'recruitment'));
        }
    }

    /** @param array<string, mixed> $body */
    public function ok(array $body = [], int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->noStore(new JsonResponse(['ok' => true] + $body, $status));
    }

    private function failure(string $reason, string $message): JsonResponse
    {
        return $this->noStore(new JsonResponse(['ok' => false, 'error' => $reason, 'message' => $message], match (true) {
            'not_found' === $reason => Response::HTTP_NOT_FOUND,
            \in_array($reason, self::FORBIDDEN, true) => Response::HTTP_FORBIDDEN,
            \in_array($reason, ['step_up_locked', 'too_many_attempts', 'job_rate_limited'], true) => Response::HTTP_TOO_MANY_REQUESTS,
            \in_array($reason, self::CONFLICT, true) => Response::HTTP_CONFLICT,
            \in_array($reason, ['appeal_file_too_large', 'appeal_files_total', 'job_file_too_large', 'job_files_total'], true) => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            'rate_provider_down' === $reason => Response::HTTP_SERVICE_UNAVAILABLE,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        }));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
