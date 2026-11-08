<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nouveau droit « currency.manage » (taux de change) : ajouté aux profils délégués PDG et DG.
 *
 * Les profils de droits sont des réglages modifiables par le Super Admin ; cette migration ne touche qu'à PDG et DG (si
 * le Super Admin en a retiré un droit, il n'est pas remis ailleurs) et ne fait rien si le droit y est déjà.
 */
final class Version20261102000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Droit currency.manage (taux de change) ajouté aux profils PDG et DG.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE admin_roles SET permissions = permissions || '[\"currency.manage\"]'::jsonb, updated_at = NOW() WHERE code IN ('pdg', 'dg') AND NOT (permissions @> '[\"currency.manage\"]'::jsonb)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE admin_roles SET permissions = permissions - 'currency.manage', updated_at = NOW() WHERE code IN ('pdg', 'dg')");
    }
}
