<?php

namespace App\Repository;

use App\Entity\SocialIdentity;
use App\Enum\SocialProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialIdentity>
 */
class SocialIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialIdentity::class);
    }

    /** Retrouve le lien existant pour (fournisseur, sub) — la clé de reconnaissance d'un utilisateur social. */
    public function findOneByProviderAndSubject(SocialProvider $provider, string $subject): ?SocialIdentity
    {
        return $this->findOneBy(['provider' => $provider, 'subject' => $subject]);
    }
}
