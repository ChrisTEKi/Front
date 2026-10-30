<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Profils d'administrateurs de départ : Finance, Comptabilité, Support, Modération.
 *
 * Ce ne sont PAS des données fictives : ce sont des ensembles de droits (référentiel de l'application), comme le
 * profil « Super Admin » de la migration initiale. Ils servent de point de départ et restent MODIFIABLES par le
 * Super Admin (is_system = FALSE) ; il peut aussi en créer d'autres et retirer/ajouter des droits à chaque admin.
 *
 * Droits (voir App\Enum\Permission) : « ressource.action ». Aucun profil ne contient les droits admins.* (réservés au Super Admin).
 */
final class Version20261020000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profils d\'administrateurs de départ : Finance, Comptabilité, Support, Modération.';
    }

    public function up(Schema $schema): void
    {
        $roles = [
            ['01920000-0000-7000-8000-000000000002', 'finance', 'Admin Finance', 'Suivi des paiements et reversements, consultation des commandes et des fournisseurs.', ['finance.view', 'finance.manage', 'orders.view', 'suppliers.view']],
            ['01920000-0000-7000-8000-000000000003', 'compta', 'Admin Comptabilité', 'Lecture des données financières et des commandes, exports comptables. Aucune modification.', ['finance.view', 'orders.view', 'suppliers.view', 'suppliers.export', 'users.export']],
            ['01920000-0000-7000-8000-000000000004', 'support', 'Admin Support client', 'Assistance : consultation des clients et commandes, suivi de colis, suspension d\'un compte.', ['users.view', 'users.suspend', 'orders.view', 'orders.update_tracking']],
            ['01920000-0000-7000-8000-000000000005', 'moderation', 'Admin Modération fournisseurs', 'Examen et approbation des dossiers fournisseurs.', ['suppliers.view', 'suppliers.approve']],
        ];
        foreach ($roles as [$id, $code, $label, $description, $permissions]) {
            $this->addSql(
                'INSERT INTO admin_roles (id, code, label, description, permissions, is_system, created_at, updated_at) VALUES (?, ?, ?, ?, ?::jsonb, FALSE, NOW(), NOW())',
                [$id, $code, $label, $description, json_encode($permissions, \JSON_THROW_ON_ERROR)],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM admin_roles WHERE code IN ('finance', 'compta', 'support', 'moderation') AND NOT EXISTS (SELECT 1 FROM admin_profiles WHERE admin_profiles.admin_role_id = admin_roles.id)");
    }
}
