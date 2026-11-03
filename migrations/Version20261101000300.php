<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Profils « PDG » et « DG » : droits étendus que le Super Admin peut déléguer à d'autres administrateurs.
 *
 * Comme les profils de départ (Finance, Support…), ce sont des ENSEMBLES DE DROITS modifiables (is_system = FALSE),
 * pas des données fictives. Aucun ne contient les droits admins.* : créer, suspendre ou exporter des administrateurs
 * reste exclusivement réservé au Super Admin (voir App\Enum\Permission::isAssignable()).
 *
 *  - PDG : tous les droits attribuables (modération, catalogue, sanctions, liste noire, suppression de compte, finance…).
 *  - DG  : comme le PDG, MAIS sans la suppression définitive d'un compte (accounts.delete) ni celle d'un produit (products.delete).
 *
 * Les droits ajoutés par les évolutions suivantes (devises, recrutement) sont ajoutés à ces profils par leur propre migration.
 */
final class Version20261101000300 extends AbstractMigration
{
    private const SHARED = [
        'users.view', 'users.suspend', 'users.export',
        'suppliers.view', 'suppliers.approve', 'suppliers.export',
        'products.view', 'products.manage', 'products.flag', 'products.feature', 'products.remove',
        'sanctions.ban', 'sanctions.freeze', 'sanctions.refund',
        'blacklist.manage',
        'orders.view', 'orders.update_tracking',
        'finance.view', 'finance.manage',
        'audit.view',
    ];

    public function getDescription(): string
    {
        return 'Profils d\'administrateurs « PDG » et « DG » (droits étendus délégables par le Super Admin).';
    }

    public function up(Schema $schema): void
    {
        $roles = [
            [
                '01920000-0000-7000-8000-000000000006', 'pdg', 'PDG',
                'Direction générale : tous les droits attribuables, y compris la suppression définitive des comptes et des produits.',
                [...self::SHARED, 'products.delete', 'accounts.delete'],
            ],
            [
                '01920000-0000-7000-8000-000000000007', 'dg', 'DG',
                'Direction : comme le PDG, sans la suppression définitive d\'un compte ni d\'un produit.',
                self::SHARED,
            ],
        ];
        foreach ($roles as [$id, $code, $label, $description, $permissions]) {
            $this->addSql(
                'INSERT INTO admin_roles (id, code, label, description, permissions, is_system, created_at, updated_at) '
                .'SELECT ?, ?, ?, ?, ?::jsonb, FALSE, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM admin_roles WHERE code = ?)',
                [$id, $code, $label, $description, json_encode($permissions, \JSON_THROW_ON_ERROR), $code],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM admin_roles WHERE code IN ('pdg', 'dg') AND NOT EXISTS (SELECT 1 FROM admin_profiles WHERE admin_profiles.admin_role_id = admin_roles.id)");
    }
}
