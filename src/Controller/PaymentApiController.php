<?php

namespace App\Controller;

use App\Account\PhoneService;
use App\Entity\User;
use App\Http\ReadsJsonPayload;
use App\Http\VerificationResponder;
use App\Payment\MobileMoneyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API JSON de la page de commande : vérification du téléphone et du numéro Mobile Money par SMS.
 *
 * Réservée aux CLIENTS connectés (règle `^/paiement` de security.yaml). Jeton CSRF exigé (en-tête X-CSRF-Token).
 * Le contrôleur reste fin : toute la logique est dans PhoneService et MobileMoneyService.
 *
 *   POST /paiement/telephone/envoyer        {phone}           → envoie le code SMS de vérification du téléphone
 *   POST /paiement/telephone/verifier       {code}            → valide le téléphone (obligatoire avant la 1re commande)
 *   POST /paiement/mobile-money/envoyer     {number}          → contrôle le numéro Mobile Money, envoie un code si besoin
 *   POST /paiement/mobile-money/verifier    {number, code}    → valide le numéro Mobile Money
 */
#[Route('/paiement', name: 'payment_', defaults: ['_csrf_token_id' => 'auth'])]
final class PaymentApiController extends AbstractController
{
    use ReadsJsonPayload;

    public function __construct(
        private readonly PhoneService $phone,
        private readonly MobileMoneyService $mobileMoney,
        private readonly VerificationResponder $responder,
    ) {
    }

    #[Route('/telephone/envoyer', name: 'phone_send', methods: ['POST'])]
    public function sendPhoneCode(Request $request): JsonResponse
    {
        return $this->responder->respond($this->phone->start($this->client(), $this->text($this->payload($request), 'phone')));
    }

    #[Route('/telephone/verifier', name: 'phone_verify', methods: ['POST'])]
    public function verifyPhone(Request $request): JsonResponse
    {
        return $this->responder->respond($this->phone->confirm($this->client(), $this->text($this->payload($request), 'code')));
    }

    #[Route('/mobile-money/envoyer', name: 'momo_send', methods: ['POST'])]
    public function sendMobileMoneyCode(Request $request): JsonResponse
    {
        return $this->responder->respond($this->mobileMoney->start($this->client(), $this->text($this->payload($request), 'number')));
    }

    #[Route('/mobile-money/verifier', name: 'momo_verify', methods: ['POST'])]
    public function verifyMobileMoney(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        return $this->responder->respond($this->mobileMoney->confirm($this->client(), $this->text($data, 'number'), $this->text($data, 'code')));
    }

    /** Le client connecté (la règle d'accès garantit qu'il existe ; ce contrôle protège d'un oubli de configuration). */
    private function client(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isClient()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
