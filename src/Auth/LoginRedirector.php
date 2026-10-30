<?php

namespace App\Auth;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Détermine où envoyer la personne une fois connectée.
 *
 *  1. La page qu'elle voulait ouvrir avant d'être invitée à se connecter (mémorisée en session), si elle est SÛRE ;
 *  2. sinon l'accueil de son espace : admin → /admin, fournisseur → /fournisseur, client → accueil.
 *
 * Sécurité « open redirect » : on n'accepte qu'un chemin relatif de CE site. Sans cette règle, un lien piégé
 * « /connexion?next=https://site-pirate.example » renverrait la victime, juste après sa connexion,
 * vers un faux site imitant GoBuy.
 */
final class LoginRedirector
{
    /** Clé de session standard de Symfony pour la page demandée avant connexion (pare-feu « main »). */
    public const TARGET_PATH_KEY = '_security.main.target_path';

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /** Mémorise la page voulue si elle est sûre (appelé quand /connexion?next=… est ouvert). */
    public function remember(SessionInterface $session, ?string $path): void
    {
        if (null !== $path && self::isSafePath($path)) {
            $session->set(self::TARGET_PATH_KEY, $path);
        }
    }

    /** Retourne (et oublie) la destination après connexion. */
    public function targetFor(User $user, SessionInterface $session): string
    {
        $wanted = $session->get(self::TARGET_PATH_KEY);
        $session->remove(self::TARGET_PATH_KEY);

        if (\is_string($wanted) && self::isSafePath($wanted) && $this->isAllowedFor($wanted, $user)) {
            return $wanted;
        }

        return match (true) {
            $user->isAdmin() => $this->urls->generate('app_admin_dashboard'),
            $user->isSupplier() => $this->urls->generate('app_supplier_dashboard'),
            default => $this->urls->generate('app_home'),
        };
    }

    /**
     * Un chemin est sûr s'il commence par UN seul « / » (« //hote » est une URL « protocole relatif » vers un autre site),
     * ne contient ni barre oblique inverse (certains navigateurs la traitent comme « / ») ni caractère de contrôle.
     */
    public static function isSafePath(string $path): bool
    {
        return str_starts_with($path, '/')
            && !str_starts_with($path, '//')
            && !str_contains($path, '\\')
            && 1 !== preg_match('/[\x00-\x1f\x7f]/', $path)
            && \strlen($path) <= 512
            // On ne renvoie jamais vers la page de connexion elle-même (boucle).
            && !str_starts_with($path, '/connexion')
            && !str_starts_with($path, '/auth/');
    }

    /** Ne pas renvoyer un client vers /admin ni l'inverse : la page serait refusée (403) juste après la connexion. */
    private function isAllowedFor(string $path, User $user): bool
    {
        if (str_starts_with($path, '/admin')) {
            return $user->isAdmin();
        }
        if (str_starts_with($path, '/fournisseur')) {
            return $user->isSupplier();
        }

        return true;
    }
}
