<?php

namespace App\Controller;

use App\Account\AccountActionException;
use App\Account\AvatarService;
use App\Entity\User;
use App\Http\AccountResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Photo de profil de la personne connectée.
 *
 *   GET  /profil/photo           → l'image du compte connecté (404 s'il n'en a pas)
 *   POST /compte/api/photo       → remplace la photo (multipart, champ « photo »), CSRF exigé
 *   POST /compte/api/photo/supprimer → supprime la photo, CSRF exigé
 *
 * Aucune route ne reçoit d'identifiant : on ne sert et on ne modifie QUE la photo du compte connecté, jamais celle d'un autre.
 */
final class ProfilePhotoController extends AbstractController
{
    public function __construct(
        private readonly AvatarService $avatars,
        private readonly AccountResponder $responder,
    ) {
    }

    #[Route('/profil/photo', name: 'app_profile_photo', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $user = $this->user();
        $avatar = $this->avatars->find($user);
        if (null === $avatar) {
            throw $this->createNotFoundException();
        }

        $response = new Response($avatar->getBytes(), Response::HTTP_OK, ['Content-Type' => $avatar->getMimeType()]);
        // « private, no-cache » : le navigateur garde l'image mais DOIT la revalider à chaque fois (réponse 304 sans corps, et refusée
        // si la session est fermée) ; « no-store » serait inutilement lourd pour une image de 40 Ko affichée sur chaque page.
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', 'inline; filename="photo.jpg"');
        $response->setEtag((string) $user->avatarVersion());
        $response->isNotModified($request);

        return $response;
    }

    #[Route('/compte/api/photo', name: 'account_api_photo_set', methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    public function set(Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($request): JsonResponse {
            $user = $this->user();
            $file = $request->files->get('photo');
            if (!$file instanceof UploadedFile) {
                // Aucun fichier reçu. Si la requête avait pourtant un corps, PHP l'a jeté lui-même (plus gros que post_max_size).
                throw new AccountActionException((int) $request->headers->get('Content-Length', '0') > 0 ? 'photo_too_large' : 'photo_required');
            }
            if (!$file->isValid()) {
                throw new AccountActionException(\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? 'photo_too_large' : 'photo_invalid');
            }

            // On lit le fichier en mémoire (5 Mo au plus, contrôlé par le processeur) ; le fichier temporaire est supprimé par PHP.
            $this->avatars->replace($user, (string) file_get_contents($file->getPathname()));

            return $this->responder->ok(['photoUrl' => $this->photoUrl($user)]);
        });
    }

    #[Route('/compte/api/photo/supprimer', name: 'account_api_photo_remove', methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    public function remove(): JsonResponse
    {
        $this->avatars->remove($this->user());

        return $this->responder->ok(['photoUrl' => null]);
    }

    private function photoUrl(User $user): ?string
    {
        return $user->hasAvatar() ? $this->generateUrl('app_profile_photo', ['v' => $user->avatarVersion()]) : null;
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
