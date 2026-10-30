<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Authentificateur « de passage ».
 *
 * Il ne traite JAMAIS de requête entrante (supports() renvoie toujours false) : la vérification des
 * identifiants et des étapes (OTP, 2FA…) est faite en amont par App\Auth\LoginFlow. Quand tout est validé,
 * le service appelle `Security::login($user, FlowAuthenticator::class)` : Symfony crée alors le jeton de
 * connexion, protège contre la fixation de session et applique le UserChecker.
 *
 * Pourquoi cette architecture ? Elle garantit qu'un jeton Symfony n'existe QUE pour un utilisateur ayant
 * franchi toutes les étapes de sécurité.
 */
final class FlowAuthenticator extends AbstractAuthenticator
{
    /** Ne s'active jamais automatiquement sur une requête. */
    public function supports(Request $request): ?bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        // Ne devrait jamais être appelé (voir supports()).
        throw new \LogicException('FlowAuthenticator ne s\'utilise que via Security::login().');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // la réponse est construite par le contrôleur appelant
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
