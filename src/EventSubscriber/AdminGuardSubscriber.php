<?php

namespace App\EventSubscriber;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Security\AdminAccessPolicy;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Deux protections supplémentaires pour les comptes ADMINISTRATEUR, vérifiées à chaque requête :
 *
 *  1. Restriction d'IP (si ADMIN_ALLOWED_IPS est renseignée) : un admin déjà connecté dont l'adresse n'est plus
 *     autorisée (changement de réseau, session volée et rejouée ailleurs) est immédiatement refusé.
 *  2. Expiration par INACTIVITÉ : 15 minutes sans action → déconnexion. Un administrateur qui quitte son poste
 *     sans se déconnecter ne laisse pas un accès ouvert aux données de tous les clients.
 *
 * Ne s'applique pas aux clients ni aux fournisseurs (leur session dure jusqu'à 8 h d'inactivité).
 */
final class AdminGuardSubscriber implements EventSubscriberInterface
{
    /** Inactivité maximale d'un administrateur, en secondes (15 min). */
    public const IDLE_TIMEOUT = 900;

    private const SESSION_KEY = 'gobuy.admin_last_activity';

    public function __construct(
        private readonly Security $security,
        private readonly AdminAccessPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priorité 7 : juste APRÈS le pare-feu (8), qui a déjà identifié l'utilisateur.
        return [KernelEvents::REQUEST => [['onRequest', 7]]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isAdmin()) {
            return;
        }
        $request = $event->getRequest();

        // --- 1. Réseau autorisé ---
        if (!$this->policy->isIpAllowed($request->getClientIp())) {
            $this->audit->record(AuditAction::ACCESS_DENIED, $user, 'user', $user->getId()->toRfc4122(), ['reason' => 'ip_not_allowed', 'ip' => $request->getClientIp()]);
            $this->security->logout(false);
            $event->setResponse(new Response('Forbidden', Response::HTTP_FORBIDDEN));

            return;
        }

        // --- 2. Inactivité ---
        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        $last = (int) $session->get(self::SESSION_KEY, $now);
        if ($now - $last > self::IDLE_TIMEOUT) {
            $session->remove(self::SESSION_KEY);
            $this->security->logout(false); // false : pas de contrôle CSRF, c'est le système qui déconnecte
            $next = $request->isMethod('GET') ? ['next' => $request->getRequestUri(), 'reason' => 'idle'] : ['reason' => 'idle'];
            $event->setResponse(new RedirectResponse($this->urls->generate('app_auth', $next)));

            return;
        }
        $session->set(self::SESSION_KEY, $now);
    }
}
