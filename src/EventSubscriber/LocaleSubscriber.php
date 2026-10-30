<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Choisit la LANGUE de chaque requête (français, anglais ou espagnol).
 *
 * Ordre de priorité :
 *   1. le choix explicite de la personne (sélecteur de langue) : session, puis cookie `gobuy_locale` (1 an) ;
 *   2. la langue du navigateur (en-tête HTTP « Accept-Language »), si elle fait partie des langues gérées ;
 *   3. le français par défaut.
 *
 * Un utilisateur connecté retrouve sa langue enregistrée : elle est copiée en session à la connexion
 * (voir AuthEventSubscriber).
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    /** Nom du cookie qui mémorise le choix de langue. */
    public const COOKIE = 'gobuy_locale';

    /** Clé de session (clé standard « _locale » de Symfony). */
    public const SESSION_KEY = '_locale';

    /**
     * @param list<string> $locales Langues gérées (parameters: app.locales)
     */
    public function __construct(
        #[Autowire('%app.locales%')]
        private readonly array $locales,
        #[Autowire('%app.default_locale%')]
        private readonly string $defaultLocale,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité 20 : AVANT le routeur (16) et le pare-feu (8), pour que tout le reste connaisse déjà la langue.
            KernelEvents::REQUEST => [['onRequest', 20]],
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $request->setLocale($this->detect($request));
    }

    /** Annonce la langue de la page dans l'en-tête HTTP (utile aux lecteurs d'écran et aux moteurs de recherche). */
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && !$event->getResponse()->headers->has('Content-Language')) {
            $event->getResponse()->headers->set('Content-Language', $event->getRequest()->getLocale());
        }
    }

    private function detect(Request $request): string
    {
        // 1a. Choix mémorisé en session.
        if ($request->hasSession()) {
            $fromSession = $request->getSession()->get(self::SESSION_KEY);
            if (\is_string($fromSession) && $this->isSupported($fromSession)) {
                return $fromSession;
            }
        }

        // 1b. Choix mémorisé dans le cookie.
        $fromCookie = $request->cookies->get(self::COOKIE);
        if (\is_string($fromCookie) && $this->isSupported($fromCookie)) {
            return $fromCookie;
        }

        // 2. Langue préférée du navigateur, parmi nos langues (gère « fr-FR,fr;q=0.9,en;q=0.8 »).
        $preferred = $request->getPreferredLanguage($this->locales);
        if (\is_string($preferred) && $this->isSupported($preferred)) {
            return $preferred;
        }

        // 3. Français.
        return $this->defaultLocale;
    }

    private function isSupported(string $locale): bool
    {
        return \in_array($locale, $this->locales, true);
    }
}
