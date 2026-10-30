<?php

namespace App\Controller;

use App\Account\AddressBook;
use App\Account\AddressPresenter;
use App\Auth\LoginRedirector;
use App\Entity\User;
use App\Order\OrderPresenter;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pages « Mon compte », « Connexion / inscription » et « Suivi de commande ».
 *
 * Ces pages ne font qu'AFFICHER : toutes les actions (connexion, changement de mot de passe, suppression du compte…)
 * passent par les API JSON (/auth/*, /compte/api/*), qui vérifient le jeton CSRF et les droits.
 *
 * Accès (config/packages/security.yaml) : /compte et /suivi exigent d'être connecté ; /connexion est publique.
 */
final class AccountController extends AbstractController
{
    /** Écrans de la page Compte. Les comptes fournisseur et administrateur n'ont ni commandes ni adresses : seulement les 3 premiers. */
    private const SECTIONS_ALL = ['main', 'favorites', 'orders', 'addresses', 'info', 'security'];
    private const SECTIONS_PRO = ['main', 'info', 'security'];

    /** Parcours proposés par l'écran de connexion (liste blanche : jamais une valeur libre de l'URL dans la page). */
    private const AUTH_MODES = ['login', 'register', 'register_supplier', 'forgot_password'];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly AddressBook $addresses,
        private readonly AddressPresenter $addressPresenter,
        private readonly OrderPresenter $presenter,
        private readonly LoginRedirector $redirector,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/compte', name: 'app_account', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(); // inatteignable : la règle d'accès /compte exige d'être connecté
        }

        $isClient = $user->isClient();
        $allowed = $isClient ? self::SECTIONS_ALL : self::SECTIONS_PRO;
        $section = (string) $request->query->get('section', 'main');

        $response = $this->render('account/index.html.twig', [
            'user' => $user,
            'isClient' => $isClient,
            'section' => \in_array($section, $allowed, true) ? $section : 'main',
            'orders' => $isClient ? array_map($this->presenter->summary(...), $this->orders->findForClient($user)) : [],
            'addresses' => $isClient ? $this->addressPresenter->list($this->addresses->list($user)) : [],
        ]);

        // Page personnelle : jamais mise en cache (ni par le navigateur, ni par un proxy).
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/connexion', name: 'app_auth', methods: ['GET'])]
    public function auth(Request $request): Response
    {
        // Déjà connecté : inutile d'afficher le formulaire.
        $user = $this->getUser();
        if ($user instanceof User) {
            return $this->redirect($this->redirector->targetFor($user, $request->getSession()));
        }

        // « /connexion?next=/commande » : on mémorise la page voulue (seulement si c'est un chemin sûr de ce site).
        $next = $request->query->get('next');
        $this->redirector->remember($request->getSession(), \is_string($next) ? $next : null);

        // Message laissé par un retour Google/Apple en échec (SocialAuthController::fail), traduit ici.
        $flash = $request->getSession()->getFlashBag()->get('auth_error');
        $flashError = [] !== $flash && \is_string($flash[0]) && str_starts_with($flash[0], 'social.error.')
            ? $this->translator->trans($flash[0], [], 'auth')
            : null;

        $mode = (string) $request->query->get('mode', 'login');

        $response = $this->render('auth/index.html.twig', [
            'mode' => \in_array($mode, self::AUTH_MODES, true) ? $mode : 'login',
            'resume' => 'resume' === $request->query->get('view'),
            'flashError' => $flashError,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/suivi/{number}', name: 'app_order_tracking', methods: ['GET'], defaults: ['number' => null])]
    public function tracking(Request $request, ?string $number): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $number ??= $request->query->get('number');
        $number = \is_string($number) ? trim($number) : null;
        $order = null;
        // Un client ne voit QUE ses propres commandes (la recherche est limitée à lui : voir OrderRepository).
        if ($user->isClient() && null !== $number && '' !== $number) {
            $found = $this->orders->findForClientByReference($user, $number);
            $order = null !== $found ? $this->presenter->detail($found) : null;
        }

        $response = $this->render('account/tracking.html.twig', ['order' => $order, 'number' => $number, 'isClient' => $user->isClient()]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
