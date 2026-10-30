<?php

namespace App\Controller;

use App\Admin\AdminAccountService;
use App\Admin\AdminActionException;
use App\Http\ReadsJsonPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Activation d'un compte administrateur invité : il clique le lien reçu par e-mail et CHOISIT SON MOT DE PASSE.
 *
 * Page PUBLIQUE (security.yaml : `^/admin/invitation` = PUBLIC_ACCESS) : le jeton du lien, à usage unique, valable
 * 48 h, fait foi. Personne d'autre que l'invité (ni même le Super Admin) ne connaît donc le mot de passe.
 *
 * Précautions :
 *   - `Referrer-Policy: no-referrer` : le jeton est dans l'URL, il ne doit jamais fuiter vers un autre site ;
 *   - `Cache-Control: no-store` : la page n'est jamais mise en cache ;
 *   - la page GET n'affiche rien de sensible et ne consomme PAS le jeton : seul le POST (avec mot de passe valide) le consomme ;
 *   - toutes les erreurs de lien (faux, périmé, déjà utilisé) donnent le MÊME message : aucune information n'est révélée.
 */
#[Route('/admin/invitation/{userId}/{token}', name: 'admin_invitation', requirements: ['userId' => '[0-9a-fA-F-]{36}', 'token' => '[A-Za-z0-9_-]{43}'])]
final class AdminInvitationController extends AbstractController
{
    use ReadsJsonPayload;

    public function __construct(
        private readonly AdminAccountService $accounts,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** Affiche le formulaire « choisissez votre mot de passe ». */
    #[Route('', name: '', methods: ['GET'])]
    public function form(string $userId, string $token): Response
    {
        $response = $this->render('admin/invitation.html.twig', ['userId' => $userId, 'token' => $token]);

        return $this->harden($response);
    }

    /** Enregistre le mot de passe et active le compte. */
    #[Route('', name: '_submit', methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    public function submit(string $userId, string $token, Request $request): JsonResponse
    {
        $data = $this->payload($request);
        $password = (string) ($data['password'] ?? '');
        if ($password !== (string) ($data['passwordConfirm'] ?? '')) {
            return $this->harden(new JsonResponse([
                'ok' => false,
                'error' => 'password_mismatch',
                'message' => $this->translator->trans('invitation.error.mismatch', [], 'admin'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        try {
            $errors = $this->accounts->acceptInvitation($userId, $token, $password);
        } catch (AdminActionException $e) {
            return $this->harden(new JsonResponse([
                'ok' => false,
                'error' => $e->reason,
                'message' => $this->translator->trans('error.'.$e->reason, $e->params, 'admin'),
            ], 'too_many_attempts' === $e->reason ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if ([] !== $errors) {
            return $this->harden(new JsonResponse([
                'ok' => false,
                'error' => 'weak_password',
                'message' => implode(' ', $errors),
                'errors' => ['password' => $errors],
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->harden(new JsonResponse([
            'ok' => true,
            'message' => $this->translator->trans('invitation.done', [], 'admin'),
            // Le nouvel admin se connecte ensuite normalement (mot de passe + code e-mail obligatoire).
            'redirect' => $this->generateUrl('app_auth'),
        ]));
    }

    /**
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    private function harden(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
