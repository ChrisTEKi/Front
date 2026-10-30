<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Dernier garde-fou AVANT la création de la session connectée.
 *
 * Le parcours App\Auth\LoginFlow applique déjà toutes ces règles et guide l'utilisateur (vérifier son e-mail,
 * restaurer son compte…). Ce vérificateur est une SECONDE barrière, indépendante : si un jour un autre code
 * appelait Security::login() par erreur, un compte suspendu ou non vérifié ne pourrait quand même pas entrer.
 * (Défense en profondeur : on ne se fie pas à une seule couche.)
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Compte suspendu par l'équipe, ou en cours de suppression (il faut d'abord le restaurer explicitement).
        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('account_not_active');
        }

        // Règles de vérification des moyens de contact (voir LoginFlow::nextStep pour le détail et la justification).
        $verified = match (true) {
            $user->isSupplier() => $user->isEmailVerified() && $user->isPhoneVerified(),
            $user->isAdmin() => $user->isEmailVerified() && $user->hasPassword(),
            default => $user->hasVerifiedContact(),
        };
        if (!$verified) {
            throw new CustomUserMessageAccountStatusException('account_not_verified');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // Rien à contrôler après l'authentification : tout est vérifié avant.
    }
}
