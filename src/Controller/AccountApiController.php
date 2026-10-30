<?php

namespace App\Controller;

use App\Account\AccountActionException;
use App\Account\AccountService;
use App\Account\ContactChangeService;
use App\Account\PhoneService;
use App\Account\Reauthenticator;
use App\Entity\User;
use App\Enum\OtpPurpose;
use App\Http\AccountResponder;
use App\Http\ReadsJsonPayload;
use App\Http\VerificationResponder;
use App\Security\FlowAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * API JSON des RÉGLAGES DU COMPTE (page « Mon compte ») : mot de passe, double authentification, téléphone,
 * suppression du compte.
 *
 * Réservée aux utilisateurs connectés (règle `^/compte` de security.yaml) ; jeton CSRF exigé (en-tête X-CSRF-Token).
 * Aucune route ne reçoit d'identifiant : on agit TOUJOURS sur le compte connecté, jamais sur celui d'un autre.
 * Le contrôleur reste fin : la logique est dans AccountService, Reauthenticator et PhoneService.
 *
 *   POST /compte/api/mot-de-passe              {current, new}                 → change le mot de passe (déconnecte les autres appareils)
 *   POST /compte/api/double-authentification   {email, sms, password, code}   → active / désactive la 2FA
 *   POST /compte/api/telephone                 {phone}                        → ajoute / corrige un numéro NON vérifié, envoie le code SMS
 *   POST /compte/api/telephone/verifier        {code}                         → valide ce numéro
 *   POST /compte/api/contact/demarrer          {kind, value}                  → change l'e-mail ou le téléphone : code à l'ANCIENNE adresse e-mail
 *   POST /compte/api/contact/confirmer         {code}                         → code de l'étape en cours (ancienne adresse, puis nouvelle destination)
 *   POST /compte/api/contact/renvoyer          {}                             → renvoie le code de l'étape en cours
 *   POST /compte/api/contact/annuler           {}                             → abandonne le changement en cours
 *   POST /compte/api/confirmation/envoyer      {purpose}                      → code de confirmation (comptes Google/Apple)
 *   POST /compte/api/suppression               {password, code}               → programme la suppression (30 jours) et déconnecte
 */
#[Route('/compte/api', name: 'account_api_', defaults: ['_csrf_token_id' => 'auth'])]
final class AccountApiController extends AbstractController
{
    use ReadsJsonPayload;

    public function __construct(
        private readonly AccountService $account,
        private readonly Reauthenticator $reauth,
        private readonly PhoneService $phone,
        private readonly ContactChangeService $contact,
        private readonly VerificationResponder $verification,
        private readonly AccountResponder $responder,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
    ) {
    }

    #[Route('/mot-de-passe', name: 'password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        return $this->responder->run(function () use ($data): JsonResponse {
            $this->account->changePassword($this->user(), $this->raw($data, 'current'), $this->raw($data, 'new'));

            // L'empreinte du mot de passe a changé : les AUTRES sessions sont fermées par Symfony ; celle-ci reste ouverte.
            return $this->responder->ok(['message' => $this->translator->trans('account.password_changed', [], 'auth')]);
        });
    }

    #[Route('/double-authentification', name: 'two_factor', methods: ['POST'])]
    public function twoFactor(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        return $this->responder->run(function () use ($data): JsonResponse {
            $user = $this->user();
            $this->account->setTwoFactor($user, $this->flag($data, 'email'), $this->flag($data, 'sms'), $this->raw($data, 'password'), $this->text($data, 'code'));

            return $this->responder->ok([
                'message' => $this->translator->trans('account.two_factor_saved', [], 'auth'),
                'twoFactor' => ['email' => $user->isTwoFactorEmailEnabled(), 'sms' => $user->isTwoFactorSmsEnabled()],
            ]);
        });
    }

    #[Route('/telephone', name: 'phone', methods: ['POST'])]
    public function changePhone(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        try {
            $result = $this->account->changePhone($this->user(), $this->text($data, 'phone'));
        } catch (AccountActionException $e) {
            return $this->responder->failure($e);
        }

        return $this->verification->respond($result);
    }

    // ------------------------------------------------------------------ Changement d'e-mail / de téléphone (double confirmation)

    #[Route('/contact/demarrer', name: 'contact_start', methods: ['POST'])]
    public function startContactChange(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        return $this->verification->respond($this->contact->start($this->user(), $this->text($data, 'kind'), $this->text($data, 'value')));
    }

    #[Route('/contact/confirmer', name: 'contact_confirm', methods: ['POST'])]
    public function confirmContactChange(Request $request): JsonResponse
    {
        $user = $this->user();
        $result = $this->contact->confirm($user, $this->text($this->payload($request), 'code'));

        // L'adresse e-mail fait partie de l'empreinte de session : sans cette ré-ouverture, la session en cours serait
        // elle aussi fermée au prochain clic. Les AUTRES appareils, eux, sont bien déconnectés (voulu).
        if ($result->ok && 'verified' === $result->state && 'email' === ($result->data['kind'] ?? null)) {
            $this->security->login($user, FlowAuthenticator::class, 'main');
        }

        return $this->verification->respond($result);
    }

    #[Route('/contact/renvoyer', name: 'contact_resend', methods: ['POST'])]
    public function resendContactChange(): JsonResponse
    {
        return $this->verification->respond($this->contact->resend($this->user()));
    }

    #[Route('/contact/annuler', name: 'contact_cancel', methods: ['POST'])]
    public function cancelContactChange(): JsonResponse
    {
        $this->contact->cancel();

        return $this->responder->ok();
    }

    #[Route('/telephone/verifier', name: 'phone_verify', methods: ['POST'])]
    public function verifyPhone(Request $request): JsonResponse
    {
        return $this->verification->respond($this->phone->confirm($this->user(), $this->text($this->payload($request), 'code')));
    }

    #[Route('/confirmation/envoyer', name: 'confirm_send', methods: ['POST'])]
    public function sendConfirmationCode(Request $request): JsonResponse
    {
        $purpose = 'deletion' === $this->text($this->payload($request), 'purpose') ? OtpPurpose::AccountDeletion : OtpPurpose::AccountSecurity;

        return $this->responder->run(function () use ($purpose): JsonResponse {
            $user = $this->user();
            // Pour la suppression, les règles métier (client actif, aucune commande en cours) sont vérifiées AVANT d'envoyer un code.
            $resendIn = OtpPurpose::AccountDeletion === $purpose ? $this->account->sendDeletionCode($user) : $this->reauth->sendCode($user, $purpose);

            return $this->responder->ok(['resendIn' => $resendIn]);
        });
    }

    #[Route('/suppression', name: 'delete', methods: ['POST'])]
    public function requestDeletion(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        return $this->responder->run(function () use ($data, $request): JsonResponse {
            $date = $this->account->requestDeletion($this->user(), $this->raw($data, 'password'), $this->text($data, 'code'));
            $formatter = \IntlDateFormatter::create($request->getLocale(), \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, 'Indian/Antananarivo');

            // Compte gelé : on ferme AUSSI la session en cours (les autres le sont par Symfony, le statut ayant changé).
            // Pour se reconnecter, la personne devra choisir explicitement de restaurer son compte.
            $this->security->logout(false);

            return $this->responder->ok([
                'message' => $this->translator->trans('account.deletion_scheduled', ['%date%' => false !== $formatter ? $formatter->format($date) : $date->format('Y-m-d')], 'auth'),
                'scheduledAt' => $date->format(\DATE_ATOM),
                'redirect' => $this->generateUrl('app_home'),
            ]);
        });
    }

    // ------------------------------------------------------------------ Aides

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * Lit un champ SANS retirer les espaces (un mot de passe peut légitimement commencer ou finir par un espace).
     *
     * @param array<string, mixed> $data
     */
    private function raw(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * Lit un interrupteur (true/false). Seul un vrai booléen « true » active : « false », « 0 » ou l'absence désactivent.
     *
     * @param array<string, mixed> $data
     */
    private function flag(array $data, string $key): bool
    {
        return true === ($data[$key] ?? null);
    }
}
