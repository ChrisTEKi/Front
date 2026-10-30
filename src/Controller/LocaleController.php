<?php

namespace App\Controller;

use App\Auth\LoginRedirector;
use App\EventSubscriber\LocaleSubscriber;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sélecteur de langue : /langue/fr, /langue/en, /langue/es.
 * Mémorise le choix (session + cookie d'un an) et, si la personne est connectée, l'enregistre sur son compte
 * pour que les e-mails et SMS lui parviennent aussi dans cette langue.
 */
final class LocaleController extends AbstractController
{
    #[Route('/langue/{locale}', name: 'app_locale_switch', requirements: ['locale' => 'fr|en|es'], methods: ['GET'])]
    public function switch(string $locale, Request $request, EntityManagerInterface $em): RedirectResponse
    {
        // Retour à la page d'où vient la personne (en-tête Referer), seulement si c'est une page de CE site.
        $back = $this->backTo($request);
        $response = new RedirectResponse($back ?? $this->generateUrl('app_home'));

        $request->getSession()->set(LocaleSubscriber::SESSION_KEY, $locale);
        // Cookie lisible seulement par le serveur (HttpOnly), non envoyé depuis des sites tiers (Lax).
        $response->headers->setCookie(Cookie::create(LocaleSubscriber::COOKIE, $locale, strtotime('+1 year'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));

        // Cette route est un simple lien (GET) : une page d'un AUTRE site pourrait y envoyer le navigateur d'une personne connectée.
        // La langue de la session/du cookie change alors seulement l'affichage (sans gravité), mais on n'écrit PAS dans la base
        // (langue des e-mails et SMS) : il faut que le clic vienne d'une page de GoBuy ($back non nul = Referer du même site).
        $user = $this->getUser();
        if (null !== $back && $user instanceof User && $user->getLocale() !== $locale) {
            $user->setLocale($locale);
            $em->flush();
        }

        return $response;
    }

    /** Chemin (+ requête) du Referer s'il pointe vers ce site, sinon null (le contrôleur renvoie alors à l'accueil). */
    private function backTo(Request $request): ?string
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
