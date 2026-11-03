<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suppression définitive décidée par l'équipe : origine de la demande (titulaire ou administrateur), motif, administrateur qui l'a
 * programmée et statut du compte avant la décision (rétabli si elle est annulée). Les demandes déjà en cours viennent du titulaire.
 */
final class Version20261101000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression de compte programmée par un administrateur : origine, motif, auteur, statut précédent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD deletion_origin VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD deletion_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD deletion_previous_status VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD deletion_requested_by_id UUID DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN users.deletion_origin IS \'owner | admin\'');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9CA0DA9F FOREIGN KEY (deletion_requested_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1483A5E9CA0DA9F ON users (deletion_requested_by_id)');
        // Les demandes déjà en cours viennent toutes des titulaires (la suppression par l'équipe n'existait pas).
        $this->addSql("UPDATE users SET deletion_origin = 'owner' WHERE deletion_scheduled_at IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E9CA0DA9F');
        $this->addSql('DROP INDEX IDX_1483A5E9CA0DA9F');
        $this->addSql('ALTER TABLE users DROP deletion_origin');
        $this->addSql('ALTER TABLE users DROP deletion_reason');
        $this->addSql('ALTER TABLE users DROP deletion_previous_status');
        $this->addSql('ALTER TABLE users DROP deletion_requested_by_id');
    }
}
