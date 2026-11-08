<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nouveaux droits du recrutement (recruitment.view / manage / decide) : ajoutés aux profils délégués PDG et DG.
 *
 * Les profils de droits sont des réglages modifiables par le Super Admin ; cette migration ne touche qu'à PDG et DG (si
 * le Super Admin en a retiré un droit, il n'est pas remis ailleurs) et ne fait rien si le droit y est déjà.
 */
final class Version20261105000100 extends AbstractMigration
{
    private const RIGHTS = ['recruitment.view', 'recruitment.manage', 'recruitment.decide'];

    public function getDescription(): string
    {
        return 'Droits recruitment.* (recrutement) ajoutés aux profils PDG et DG.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RIGHTS as $right) {
            $this->addSql("UPDATE admin_roles SET permissions = permissions || '[\"".$right."\"]'::jsonb, updated_at = NOW() WHERE code IN ('pdg', 'dg') AND NOT (permissions @> '[\"".$right."\"]'::jsonb)");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RIGHTS as $right) {
            $this->addSql("UPDATE admin_roles SET permissions = permissions - '".$right."', updated_at = NOW() WHERE code IN ('pdg', 'dg')");
        }
    }
}
