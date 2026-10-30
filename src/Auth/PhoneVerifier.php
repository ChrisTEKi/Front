<?php

namespace App\Auth;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistre « ce numéro de téléphone est vérifié » sur un compte — LE SEUL endroit qui le fait.
 *
 * Pourquoi un service à part : le téléphone peut être vérifié à trois endroits (pendant la connexion,
 * pendant l'inscription fournisseur, et au moment de la première commande). La règle « un numéro vérifié
 * n'appartient qu'à UN compte » doit être appliquée de la même façon partout.
 *
 * Double protection :
 *   1) on cherche d'abord si un autre compte possède déjà ce numéro vérifié ;
 *   2) l'index unique PARTIEL PostgreSQL `uniq_users_verified_phone` (WHERE phone_verified_at IS NOT NULL)
 *      tranche en dernier recours si deux personnes valident le même numéro exactement en même temps.
 */
final class PhoneVerifier
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Marque le téléphone du compte comme vérifié.
     *
     * @return bool false si ce numéro est déjà vérifié par un AUTRE compte (rien n'est alors modifié)
     */
    public function markVerified(User $user, \DateTimeImmutable $now): bool
    {
        $phone = (string) $user->getPhone();
        if ('' === $phone) {
            return false; // pas de numéro : rien à vérifier
        }

        $other = $this->users->findOneByVerifiedPhone($phone);
        if (null !== $other && !$other->getId()->equals($user->getId())) {
            return false;
        }

        $user->markPhoneVerified($now);
        $user->touch($now);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Course : un autre compte a validé ce numéro entre notre lecture et notre écriture. La base fait foi.
            $this->em->clear();

            return false;
        }

        $this->audit->record(AuditAction::PHONE_VERIFIED, $user, 'user', $user->getId()->toRfc4122());

        return true;
    }
}
