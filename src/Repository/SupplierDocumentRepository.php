<?php

namespace App\Repository;

use App\Entity\SupplierDocument;
use App\Entity\User;
use App\Enum\SupplierDocumentKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupplierDocument>
 */
class SupplierDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierDocument::class);
    }

    /**
     * Les pièces de plusieurs fournisseurs d'un coup (une requête, sans lire les fichiers eux-mêmes) :
     * identifiant du compte => nature => {id, name, size}.
     *
     * @param list<string> $userIds identifiants (UUID texte) des comptes fournisseurs
     *
     * @return array<string, array<string, array{id: string, name: string, type: string, size: int}>>
     */
    public function summariesFor(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, user_id, kind, file_name, content_type, size FROM supplier_documents WHERE user_id IN (:ids)',
            ['ids' => $userIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['user_id']][(string) $row['kind']] = ['id' => (string) $row['id'], 'name' => (string) $row['file_name'], 'type' => (string) $row['content_type'], 'size' => (int) $row['size']];
        }

        return $out;
    }

    public function findOneByOwnerAndKind(User $owner, SupplierDocumentKind $kind): ?SupplierDocument
    {
        return $this->findOneBy(['owner' => $owner, 'kind' => $kind]);
    }
}
