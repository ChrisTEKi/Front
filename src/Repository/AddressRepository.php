<?php

namespace App\Repository;

use App\Entity\Address;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Address>
 */
class AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    /**
     * Adresses d'un client : l'adresse PAR DÉFAUT d'abord, puis les plus récentes.
     *
     * @return list<Address>
     */
    public function findForUser(User $user): array
    {
        // L'identifiant (UUID v7, ordonné dans le temps) départage deux adresses créées à la même seconde.
        return $this->findBy(['user' => $user], ['isDefault' => 'DESC', 'createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** Une adresse de CE client, ou null (identifiant inconnu, mal formé, ou adresse d'un autre compte : même réponse). */
    public function findOwned(User $user, string $id): ?Address
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['user' => $user, 'id' => Uuid::fromString($id)]);
    }
}
