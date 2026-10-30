<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ajoute des en-têtes HTTP de sécurité à TOUTES les réponses.
 *
 *  - X-Content-Type-Options: nosniff     → le navigateur n'« invente » pas le type d'un fichier (anti-injection de script).
 *  - X-Frame-Options / frame-ancestors   → personne ne peut afficher GoBuy dans un cadre invisible (anti « clickjacking »).
 *  - Referrer-Policy                     → les sites tiers ne voient pas nos URL complètes.
 *  - Permissions-Policy                  → caméra, micro, géolocalisation désactivés (non utilisés).
 *  - Strict-Transport-Security (HSTS)    → en HTTPS, le navigateur refuse ensuite toute connexion non chiffrée.
 *  - Cache-Control: no-store             → les pages privées (compte, admin, paiement) ne restent pas dans le cache du navigateur :
 *                                           après déconnexion, le bouton « Retour » n'affiche plus de données personnelles.
 *
 *  - Content-Security-Policy (partielle) → cadres, <base>, plugins et cibles de formulaires limités à GoBuy.
 *
 * (Évolution possible : une politique CSP complète avec « nonce » sur les scripts. Elle demande d'adapter tous les
 *  scripts en ligne du front-end ; ici on ne pose que les directives sans risque de casser l'interface.)
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /** Préfixes de chemins dont les réponses contiennent des données personnelles. */
    private const PRIVATE_PREFIXES = ['/compte', '/admin', '/fournisseur', '/commande', '/paiement', '/suivi', '/connexion', '/auth'];

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        // Valeur par défaut ; une page plus sensible peut imposer mieux (ex. « no-referrer » sur le lien d'invitation admin).
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // Directives CSP « sûres » : interdit l'affichage dans un cadre, les balises <base> détournées et les plugins.
        if (!$headers->has('Content-Security-Policy') && 'dev' !== $this->environment) {
            // form-action 'self' : un formulaire de GoBuy ne peut envoyer ses données (mot de passe, code…) qu'à GoBuy,
            // même si une balise <form> était injectée dans la page.
            $headers->set('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'");
        }

        // HSTS uniquement si la requête est déjà en HTTPS (sinon l'en-tête est ignoré, voire gênant en local).
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $path = $request->getPathInfo();
        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $headers->set('Cache-Control', 'no-store, private');
                break;
            }
        }
    }
}
