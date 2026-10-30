<?php

namespace App\Controller;

use App\Entity\User;
use App\Http\ReadsJsonPayload;
use App\Http\ValidationErrors;
use App\Order\OrderPresenter;
use App\Order\OrderRefusedException;
use App\Order\OrderService;
use App\Order\PlaceOrderRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * API JSON de la page de commande : « Payer / Confirmer la commande ».
 *
 * Réservée aux CLIENTS connectés (règle `^/commande` de security.yaml) ; jeton CSRF exigé.
 *
 *   POST /commande/valider
 *     { "items":   [{"ref": "<identifiant catalogue>", "quantity": 2}],
 *       "address": {"fullName", "phone", "street", "city", "postalCode", "courierNote"},
 *       "payment": {"method": "installments|mobile_money|card|cash_on_delivery", "installments": 3,
 *                   "channel": "mobile_money|card", "mobileMoneyNumber": "034 12 345 67"},
 *       "idempotencyKey": "<jeton aléatoire généré par le navigateur à chaque tentative>" }
 *
 *   → 201 { "ok": true, "order": {…résumé…}, "redirect": "/suivi/GB-2026-482915" }
 *   → 200 idem si le même jeton a déjà donné une commande (renvoi réseau / double-clic) : aucune seconde commande.
 *   → 4xx { "ok": false, "error": "phone_not_verified", "message": "…" }
 */
#[Route('/commande', name: 'order_', defaults: ['_csrf_token_id' => 'auth'])]
final class OrderApiController extends AbstractController
{
    use ReadsJsonPayload;

    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderPresenter $presenter,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/valider', name: 'place', methods: ['POST'])]
    public function place(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $dto = PlaceOrderRequest::fromArray($this->payload($request));
        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            return $this->noStore(new JsonResponse([
                'ok' => false,
                'error' => 'validation',
                'message' => $this->translator->trans('error.validation', [], 'orders'),
                'errors' => ValidationErrors::toArray($violations),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        try {
            $placement = $this->orders->place($user, $dto, $request->getLocale());
        } catch (OrderRefusedException $e) {
            return $this->noStore(new JsonResponse([
                'ok' => false,
                'error' => $e->reason,
                'message' => $this->translator->trans('error.'.$e->reason, $e->params, 'orders'),
            ], $this->statusFor($e->reason)));
        }

        return $this->noStore(new JsonResponse([
            'ok' => true,
            'duplicate' => $placement->duplicate,
            'order' => $this->presenter->summary($placement->order),
            'redirect' => $this->generateUrl('app_order_tracking', ['number' => $placement->order->getOrderNumber()]),
        ], $placement->duplicate ? Response::HTTP_OK : Response::HTTP_CREATED));
    }

    private function statusFor(string $reason): int
    {
        return match ($reason) {
            'account_not_allowed' => Response::HTTP_FORBIDDEN,
            'too_many_orders' => Response::HTTP_TOO_MANY_REQUESTS,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        };
    }

    /** Réponses liées au compte : jamais mises en cache. */
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
