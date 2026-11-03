<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contestations des fournisseurs : tables `supplier_appeals` (message, statut, décision de l'équipe) et
 * `supplier_appeal_documents` (pièces jointes en bytea).
 *
 * Les deux tables disparaissent avec le compte du fournisseur (ON DELETE CASCADE) ; l'administrateur qui a tranché est
 * référencé avec ON DELETE SET NULL (la décision reste si son compte disparaît).
 */
final class Version20261101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contestations des fournisseurs : message, pièces jointes, décision de l\'équipe.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE supplier_appeal_documents (id UUID NOT NULL, file_name VARCHAR(150) NOT NULL, content_type VARCHAR(100) NOT NULL, size INT NOT NULL, content BYTEA NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, appeal_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_36E37E69F9B2547F ON supplier_appeal_documents (appeal_id)');
        $this->addSql('COMMENT ON TABLE supplier_appeal_documents IS \'Pièces jointes des contestations de fournisseurs (contenu en bytea).\'');
        $this->addSql('CREATE TABLE supplier_appeals (id UUID NOT NULL, message TEXT NOT NULL, status VARCHAR(12) NOT NULL, contested_status VARCHAR(16) NOT NULL, contested_reason TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, decision_note VARCHAR(500) DEFAULT NULL, supplier_id UUID NOT NULL, decided_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_appeals_supplier ON supplier_appeals (supplier_id, created_at)');
        $this->addSql('CREATE INDEX idx_appeals_status ON supplier_appeals (status)');
        $this->addSql('CREATE INDEX IDX_8691A3352ADD6D8C ON supplier_appeals (supplier_id)');
        $this->addSql('CREATE INDEX IDX_8691A335E26B496B ON supplier_appeals (decided_by_id)');
        $this->addSql('COMMENT ON TABLE supplier_appeals IS \'Contestations de décisions sur un dossier fournisseur (message + pièces jointes).\'');
        $this->addSql('ALTER TABLE supplier_appeal_documents ADD CONSTRAINT FK_36E37E69F9B2547F FOREIGN KEY (appeal_id) REFERENCES supplier_appeals (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE supplier_appeals ADD CONSTRAINT FK_8691A3352ADD6D8C FOREIGN KEY (supplier_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE supplier_appeals ADD CONSTRAINT FK_8691A335E26B496B FOREIGN KEY (decided_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier_appeal_documents DROP CONSTRAINT FK_36E37E69F9B2547F');
        $this->addSql('ALTER TABLE supplier_appeals DROP CONSTRAINT FK_8691A3352ADD6D8C');
        $this->addSql('ALTER TABLE supplier_appeals DROP CONSTRAINT FK_8691A335E26B496B');
        $this->addSql('DROP TABLE supplier_appeal_documents');
        $this->addSql('DROP TABLE supplier_appeals');
    }
}
