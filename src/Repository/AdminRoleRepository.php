<?php

namespace App\Repository;

use App\Entity\AdminRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminRole>
 */
class AdminRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminRole::class);
    }

    public function findOneByCode(string $code): ?AdminRole
    {
        return $this->findOneBy(['code' => mb_strtolower(trim($code))]);
    }

    /**
     * Tous les profils, le profil système en premier.
     *
     * @return list<AdminRole>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['isSystem' => 'DESC', 'label' => 'ASC']);
    }
}
