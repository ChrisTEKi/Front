<?php

namespace App\Repository;

use App\Entity\MobileMoneyAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MobileMoneyAccount>
 */
class MobileMoneyAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MobileMoneyAccount::class);
    }

    /** Numéro Mobile Money (E.164) déjà enregistré par ce client, vérifié ou non. */
    public function findOneByUserAndMsisdn(User $user, string $msisdn): ?MobileMoneyAccount
    {
        return $this->findOneBy(['user' => $user, 'msisdn' => $msisdn]);
    }
}
