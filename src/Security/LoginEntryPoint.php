<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Point d'entrée : que répondre à un visiteur NON connecté qui demande une page protégée ?
 *  - Page HTML  → redirection vers /connexion?next=<page demandée> (il y revient après connexion).
 *  - Appel JSON → réponse 401 {"ok":false,"error":{"code":"unauthenticated"}}.
 */
final class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // Les appels AJAX (fetch) annoncent qu'ils attendent du JSON : on évite de leur renvoyer une page HTML.
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['ok' => false, 'error' => ['code' => 'unauthenticated']], Response::HTTP_UNAUTHORIZED);
        }

        // On mémorise la page voulue (chemin + query) pour y retourner après la connexion.
        // Seuls les GET sont mémorisés : rejouer un POST après connexion n'aurait pas de sens.
        $params = [];
        if ($request->isMethod('GET')) {
            $params['next'] = $request->getRequestUri();
        }

        return new RedirectResponse($this->urls->generate('app_auth', $params));
    }
}
