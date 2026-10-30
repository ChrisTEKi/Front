<?php

namespace App\Security;

use App\Entity\AdminProfile;
use App\Entity\User;
use App\Enum\Permission;
use App\Repository\AdminProfileRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Décide si un administrateur possède une permission fine, ex. :
 *
 *     #[IsGranted('users.export')]                       // sur un contrôleur
 *     {% if is_granted('finance.view') %} … {% endif %}   // dans un template
 *
 * Les noms d'attributs sont les valeurs de l'enum App\Enum\Permission.
 *
 * Règles :
 *   - seul un compte administrateur peut avoir des permissions ;
 *   - le Super Admin possède TOUTES les permissions ;
 *   - un admin simple : permissions de son profil (rôle) + permissions ajoutées − permissions retirées.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    /** Cache mémoire par requête : évite une requête SQL à chaque is_granted() d'un même écran. */
    private ?AdminProfile $profile = null;
    private ?string $profileForUser = null;

    public function __construct(private readonly AdminProfileRepository $profiles)
    {
    }

    /** Ce voteur ne traite que les attributs qui sont des permissions GoBuy (les autres, ex. ROLE_ADMIN, ne le concernent pas). */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return null !== Permission::tryFrom($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isAdmin() || !$user->isActive()) {
            return false;
        }

        $permission = Permission::from($attribute);

        // Le Super Admin passe partout (les permissions « admins.* » lui sont d'ailleurs réservées).
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Les permissions de gestion des administrateurs ne sont JAMAIS accordées à un admin simple, quoi qu'il y ait en base.
        if (!$permission->isAssignable()) {
            return false;
        }

        return $this->profileOf($user)?->hasPermission($permission) ?? false;
    }

    private function profileOf(User $user): ?AdminProfile
    {
        $id = $user->getId()->toRfc4122();
        if ($this->profileForUser !== $id) {
            $this->profile = $this->profiles->findOneByUser($user);
            $this->profileForUser = $id;
        }

        return $this->profile;
    }
}
