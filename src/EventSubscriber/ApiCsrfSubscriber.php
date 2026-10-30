<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Protection CSRF des appels JSON (fetch) du site.
 *
 * Une route déclare qu'elle veut cette protection avec `defaults: ['_csrf_token_id' => 'auth']`.
 * Pour toute requête qui MODIFIE des données (POST/PUT/PATCH/DELETE), le navigateur doit envoyer l'en-tête
 * `X-CSRF-Token` contenant le jeton généré par la page (balise <meta name="csrf-token">).
 *
 * Pourquoi : sans jeton, un site pirate pourrait faire envoyer par le navigateur d'une victime connectée une
 * requête « supprimer mon compte » vers GoBuy (les cookies partent automatiquement). Le site pirate ne peut pas
 * lire notre jeton (règle de même origine du navigateur) : sa requête est donc rejetée.
 * (Le cookie de session est en plus SameSite=Lax, une seconde barrière.)
 */
final class ApiCsrfSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $tokenId = $request->attributes->get('_csrf_token_id');
        if (!\is_string($tokenId) || $request->isMethodSafe()) {
            return; // route non protégée, ou requête de simple lecture
        }

        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if ('' === $token || !$this->csrf->isTokenValid(new CsrfToken($tokenId, $token))) {
            // On remplace le contrôleur par une réponse 403 : le code métier n'est jamais exécuté.
            $event->setController(static fn (): Response => new JsonResponse(
                ['ok' => false, 'error' => 'csrf', 'message' => 'Invalid security token. Reload the page and try again.'],
                Response::HTTP_FORBIDDEN,
            ));
        }
    }
}
