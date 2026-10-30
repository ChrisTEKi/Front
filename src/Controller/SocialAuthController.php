<?php

namespace App\Controller;

use App\Auth\FlowStep;
use App\Auth\LoginFlow;
use App\Auth\LoginRedirector;
use App\Enum\SocialProvider;
use App\Social\OidcClient;
use App\Social\SocialAuthException;
use App\Social\SocialAuthService;
use App\Social\SocialConfig;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;


/**
 * Inscription / connexion rapide avec Google ou Apple.
 *
 *   GET  /auth/social/{provider}            → redirige vers Google/Apple (début)
 *   GET  /auth/social/google/callback       → retour de Google (paramètres dans l'URL)
 *   POST /auth/social/apple/callback        → retour d'Apple (paramètres dans un formulaire POST « form_post »)
 *   GET  /auth/social/apple/finish          → suite du retour d'Apple (voir plus bas)
 *
 * Pourquoi un « finish » pour Apple ? Le retour d'Apple est un POST venant d'un AUTRE site : le navigateur
 * n'envoie pas notre cookie de session dans ce cas (cookie SameSite=Lax, voir framework.yaml), donc nous ne pouvons
 * pas encore vérifier le paramètre `state` contre la session. On met donc les paramètres de côté quelques secondes
 * (cache serveur) et on redirige (303) vers une requête GET normale : celle-là porte le cookie, et la vérification
 * du `state` peut se faire. Sans cette astuce, la protection CSRF serait impossible ou il faudrait affaiblir le cookie.
 */
#[Route('/auth/social', name: 'social_')]
final class SocialAuthController extends AbstractController
{
    /** Délai maximal entre le départ vers le fournisseur et le retour : 10 min. */
    private const MAX_AGE_SECONDS = 600;

    public function __construct(
        private readonly OidcClient $oidc,
        private readonly SocialConfig $config,
        private readonly SocialAuthService $service,
        private readonly LoginFlow $flow,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoginRedirector $redirector,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $socialCallbackIpLimiter,
    ) {
    }

    #[Route('/{provider}', name: 'start', requirements: ['provider' => 'google|apple'], methods: ['GET'])]
    public function start(string $provider, Request $request): Response
    {
        $provider = SocialProvider::from($provider);
        if (!$this->config->isConfigured($provider)) {
            return $this->fail($request, 'not_configured');
        }

        $data = $this->oidc->start($provider);

        // On garde state / nonce / verifier EN SESSION (côté serveur) : le navigateur ne voit que `state` et `nonce`
        // dans l'URL du fournisseur, jamais le verifier.
        $request->getSession()->set($this->sessionKey($provider), [
            'state' => $data['state'],
            'nonce' => $data['nonce'],
            'verifier' => $data['verifier'],
            'at' => time(),
        ]);

        // Page demandée avant la connexion (?next=/commande) : conservée pour après le retour.
        $next = $request->query->get('next');
        if (\is_string($next)) {
            $this->redirector->remember($request->getSession(), $next);
        }

        return new RedirectResponse($data['url']);
    }

    /** Retour du fournisseur. Google : GET avec code + state. Apple : POST « form_post » (stocké puis redirigé). */
    #[Route('/{provider}/callback', name: 'callback', requirements: ['provider' => 'google|apple'], methods: ['GET', 'POST'])]
    public function callback(string $provider, Request $request): Response
    {
        $provider = SocialProvider::from($provider);

        if ($request->isMethod('POST')) {
            // --- Apple : on met les paramètres de côté puis on redirige vers un GET (voir l'explication en tête de classe). ---
            // Ce POST peut venir de n'importe quel site et chaque appel écrit une entrée dans le cache : on plafonne donc
            // le nombre d'appels par adresse IP et la taille de chaque champ (valeurs d'Apple : bien en dessous de ces limites).
            if (!$this->socialCallbackIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                return $this->fail($request, 'invalid_response');
            }
            $handle = bin2hex(random_bytes(24));
            $item = $this->cache->getItem('social_cb_'.$handle)
                ->set([
                    'code' => mb_substr((string) $request->request->get('code', ''), 0, 4096),
                    'state' => mb_substr((string) $request->request->get('state', ''), 0, 256),
                    'error' => mb_substr((string) $request->request->get('error', ''), 0, 128),
                    'user' => mb_substr((string) $request->request->get('user', ''), 0, 4096),
                ])
                ->expiresAfter(120); // 2 minutes suffisent pour la redirection immédiate
            $this->cache->save($item);

            // 303 « See Other » : le navigateur répète la demande en GET (et non en POST).
            return $this->redirectToRoute('social_finish', ['provider' => $provider->value, 'h' => $handle], Response::HTTP_SEE_OTHER);
        }

        return $this->complete($provider, $request, [
            'code' => (string) $request->query->get('code', ''),
            'state' => (string) $request->query->get('state', ''),
            'error' => (string) $request->query->get('error', ''),
            'user' => '',
        ]);
    }

    /** Suite du retour d'Apple, en GET (le cookie de session est présent). */
    #[Route('/{provider}/finish', name: 'finish', requirements: ['provider' => 'google|apple'], methods: ['GET'])]
    public function finish(string $provider, Request $request): Response
    {
        $provider = SocialProvider::from($provider);
        $handle = (string) $request->query->get('h', '');
        if (1 !== preg_match('/^[a-f0-9]{48}$/', $handle)) {
            return $this->fail($request, 'invalid_response');
        }

        $item = $this->cache->getItem('social_cb_'.$handle);
        $stored = $item->isHit() ? $item->get() : null;
        $this->cache->deleteItem('social_cb_'.$handle); // usage unique
        if (!\is_array($stored)) {
            return $this->fail($request, 'invalid_response');
        }

        return $this->complete($provider, $request, $stored);
    }

    /**
     * @param array{code: string, state: string, error: string, user: string} $params
     */
    private function complete(SocialProvider $provider, Request $request, array $params): Response
    {
        $session = $request->getSession();

        // Les données de départ ne servent QU'UNE fois : on les retire de la session dès maintenant.
        $pending = $session->get($this->sessionKey($provider));
        $session->remove($this->sessionKey($provider));

        // L'utilisateur a refusé chez le fournisseur (bouton « Annuler »).
        if ('' !== $params['error']) {
            return $this->fail($request, 'access_denied' === $params['error'] || 'user_cancelled_authorize' === $params['error'] ? 'cancelled' : 'invalid_response');
        }

        // Vérification du `state` : prouve que le retour correspond à une demande lancée DEPUIS CETTE SESSION.
        if (!\is_array($pending) || time() - (int) ($pending['at'] ?? 0) > self::MAX_AGE_SECONDS
            || '' === $params['state'] || !hash_equals((string) $pending['state'], $params['state'])
            || '' === $params['code']) {
            return $this->fail($request, 'invalid_response');
        }

        try {
            $claims = $this->oidc->exchange($provider, $params['code'], (string) $pending['verifier'], (string) $pending['nonce']);
            $user = $this->service->resolveUser($claims, $request->getLocale(), $this->appleName($params['user']));
        } catch (SocialAuthException $e) {
            $this->logger->warning('Connexion sociale refusée ({provider}) : {reason}', ['provider' => $provider->value, 'reason' => $e->getMessage()]);

            return $this->fail($request, $e->reason);
        }

        $outcome = $this->flow->startAfterSocial($user, $provider);

        return match (true) {
            FlowStep::Done === $outcome->step => new RedirectResponse((string) $outcome->redirect),
            FlowStep::Blocked === $outcome->step => $this->fail($request, 'blocked'),
            // 2FA, restauration de compte… : la page de connexion reprend le parcours là où il en est (GET /auth/state).
            default => $this->redirectToRoute('app_auth', ['view' => 'resume']),
        };
    }

    /** Retour vers la page de connexion avec un message d'erreur (clé « social.error.<code> » affichée par la page). */
    private function fail(Request $request, string $reason): RedirectResponse
    {
        $request->getSession()->getFlashBag()->add('auth_error', 'social.error.'.$reason);

        return $this->redirectToRoute('app_auth');
    }

    private function sessionKey(SocialProvider $provider): string
    {
        return 'gobuy.social.'.$provider->value;
    }

    /**
     * Apple envoie le nom UNIQUEMENT à la toute première autorisation, dans un champ JSON `user` du formulaire :
     * {"name":{"firstName":"Jean","lastName":"Dupont"},"email":"…"}. Il n'est PAS dans le jeton signé : on ne s'en
     * sert que comme nom d'affichage (jamais pour l'identité ni les droits).
     */
    private function appleName(string $userField): ?string
    {
        if ('' === $userField) {
            return null;
        }
        try {
            $data = json_decode($userField, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $first = \is_string($data['name']['firstName'] ?? null) ? trim($data['name']['firstName']) : '';
        $last = \is_string($data['name']['lastName'] ?? null) ? trim($data['name']['lastName']) : '';
        $full = trim($first.' '.$last);

        return '' !== $full ? mb_substr($full, 0, 100) : null;
    }
}
