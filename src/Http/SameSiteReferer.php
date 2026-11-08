<?php

namespace App\Http;

use App\Auth\LoginRedirector;
use Symfony\Component\HttpFoundation\Request;

/**
 * Page d'où vient la personne (en-tête Referer), SEULEMENT si c'est une page de ce site : sert aux petits sélecteurs
 * (langue, devise) pour revenir sur la page courante. Un Referer d'un autre site est ignoré (jamais de redirection vers l'extérieur).
 */
final class SameSiteReferer
{
    /** Chemin (+ requête) du Referer s'il pointe vers ce site, sinon null. */
    public static function pathFrom(Request $request): ?string
    {
        $referer = (string) $request->headers->get('Referer', '');
        $parts = parse_url($referer);
        if (false !== $parts && isset($parts['host']) && $parts['host'] === $request->getHost()) {
            $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            if (LoginRedirector::isSafePath($path) || str_starts_with($path, '/connexion')) {
                return $path;
            }
        }

        return null;
    }
}
