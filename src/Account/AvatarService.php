<?php

namespace App\Account;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Entity\UserAvatar;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Photo de profil d'un compte (tous types de comptes : client, fournisseur, administrateur).
 *
 * L'image reçue est TOUJOURS ré-encodée (AvatarImageProcessor) avant d'être enregistrée dans `user_avatars` ; une seule photo
 * par compte (la nouvelle remplace l'ancienne). On n'agit que sur le compte connecté.
 */
final class AvatarService
{
    public function __construct(
        private readonly AvatarImageProcessor $processor,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $avatarUploadLimiter,
    ) {
    }

    /**
     * Remplace la photo du compte.
     *
     * @throws AccountActionException « too_many_attempts », « photo_required », « photo_too_large », « photo_unsupported », « photo_invalid »
     */
    public function replace(User $user, string $bytes): void
    {
        // Le traitement d'une image coûte du processeur : 10 envois par heure et par compte.
        $limit = $this->avatarUploadLimiter->create($user->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            throw new AccountActionException('too_many_attempts', ['%minutes%' => max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()) / 60))]);
        }

        $jpeg = $this->processor->process($bytes);
        $now = $this->clock->now();

        $avatar = $this->em->find(UserAvatar::class, $user->getId());
        if (null === $avatar) {
            $this->em->persist(new UserAvatar($user, $jpeg, 'image/jpeg', $now));
        } else {
            $avatar->replace($jpeg, 'image/jpeg', $now);
        }
        $user->setAvatarUpdatedAt($now);
        $user->touch($now);
        $this->em->flush();

        // Le journal garde le fait, jamais l'image.
        $this->audit->record(AuditAction::AVATAR_CHANGED, $user, 'user', $user->getId()->toRfc4122(), ['action' => 'set', 'bytes' => \strlen($jpeg)]);
    }

    /** Supprime la photo (sans effet si le compte n'en a pas). */
    public function remove(User $user): void
    {
        $avatar = $this->em->find(UserAvatar::class, $user->getId());
        if (null === $avatar && !$user->hasAvatar()) {
            return;
        }
        if (null !== $avatar) {
            $this->em->remove($avatar);
        }
        $user->setAvatarUpdatedAt(null);
        $user->touch($this->clock->now());
        $this->em->flush();

        $this->audit->record(AuditAction::AVATAR_CHANGED, $user, 'user', $user->getId()->toRfc4122(), ['action' => 'removed']);
    }

    public function find(User $user): ?UserAvatar
    {
        return $user->hasAvatar() ? $this->em->find(UserAvatar::class, $user->getId()) : null;
    }
}
