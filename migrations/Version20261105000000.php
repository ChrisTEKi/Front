<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recrutement : offres d'emploi, candidatures (candidats sans compte), pièces (CV, lettre de motivation en bytea) et entretiens.
 *
 * Une offre qui a reçu des candidatures ne se supprime pas (RESTRICT) ; une candidature emporte ses pièces et ses entretiens (CASCADE).
 */
final class Version20261105000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recrutement : offres, candidatures, pièces jointes et entretiens.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE application_documents (id UUID NOT NULL, kind VARCHAR(12) NOT NULL, file_name VARCHAR(150) NOT NULL, content_type VARCHAR(100) NOT NULL, size INT NOT NULL, content BYTEA NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, application_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_application_document_kind ON application_documents (application_id, kind)');
        $this->addSql('CREATE INDEX IDX_26B108893E030ACD ON application_documents (application_id)');
        $this->addSql('COMMENT ON TABLE application_documents IS \'CV et lettres de motivation des candidatures (contenu en bytea).\'');
        $this->addSql('CREATE TABLE interviews (id UUID NOT NULL, mode VARCHAR(10) NOT NULL, scheduled_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, duration_minutes SMALLINT NOT NULL, location VARCHAR(200) DEFAULT NULL, note VARCHAR(500) DEFAULT NULL, status VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, application_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_interviews_application ON interviews (application_id, scheduled_at)');
        $this->addSql('CREATE INDEX idx_interviews_when ON interviews (scheduled_at)');
        $this->addSql('CREATE INDEX IDX_3A7526823E030ACD ON interviews (application_id)');
        $this->addSql('CREATE INDEX IDX_3A752682B03A8386 ON interviews (created_by_id)');
        $this->addSql('COMMENT ON TABLE interviews IS \'Entretiens fixés avec les candidats (téléphone ou sur place).\'');
        $this->addSql('CREATE TABLE job_applications (id UUID NOT NULL, full_name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(20) NOT NULL, message TEXT DEFAULT NULL, locale VARCHAR(2) NOT NULL, status VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, refusal_note VARCHAR(500) DEFAULT NULL, offer_id UUID NOT NULL, decided_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_job_applications_offer ON job_applications (offer_id, created_at)');
        $this->addSql('CREATE INDEX idx_job_applications_status ON job_applications (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_job_application_offer_email ON job_applications (offer_id, email)');
        $this->addSql('CREATE INDEX IDX_F8AAF3DF53C674EE ON job_applications (offer_id)');
        $this->addSql('CREATE INDEX IDX_F8AAF3DFE26B496B ON job_applications (decided_by_id)');
        $this->addSql('COMMENT ON TABLE job_applications IS \'Candidatures aux offres d\'\'emploi (candidats sans compte).\'');
        $this->addSql('CREATE TABLE job_offers (id UUID NOT NULL, title VARCHAR(120) NOT NULL, department VARCHAR(80) DEFAULT NULL, location VARCHAR(120) DEFAULT NULL, contract_type VARCHAR(16) NOT NULL, description TEXT NOT NULL, profile TEXT DEFAULT NULL, status VARCHAR(8) NOT NULL, closing_date DATE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_job_offers_status ON job_offers (status, published_at)');
        $this->addSql('CREATE INDEX IDX_8A4229A6B03A8386 ON job_offers (created_by_id)');
        $this->addSql('COMMENT ON TABLE job_offers IS \'Offres d\'\'emploi de GoBuy.\'');
        $this->addSql('ALTER TABLE application_documents ADD CONSTRAINT FK_26B108893E030ACD FOREIGN KEY (application_id) REFERENCES job_applications (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE interviews ADD CONSTRAINT FK_3A7526823E030ACD FOREIGN KEY (application_id) REFERENCES job_applications (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE interviews ADD CONSTRAINT FK_3A752682B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE job_applications ADD CONSTRAINT FK_F8AAF3DF53C674EE FOREIGN KEY (offer_id) REFERENCES job_offers (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE job_applications ADD CONSTRAINT FK_F8AAF3DFE26B496B FOREIGN KEY (decided_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE job_offers ADD CONSTRAINT FK_8A4229A6B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE application_documents DROP CONSTRAINT FK_26B108893E030ACD');
        $this->addSql('ALTER TABLE interviews DROP CONSTRAINT FK_3A7526823E030ACD');
        $this->addSql('ALTER TABLE interviews DROP CONSTRAINT FK_3A752682B03A8386');
        $this->addSql('ALTER TABLE job_applications DROP CONSTRAINT FK_F8AAF3DF53C674EE');
        $this->addSql('ALTER TABLE job_applications DROP CONSTRAINT FK_F8AAF3DFE26B496B');
        $this->addSql('ALTER TABLE job_offers DROP CONSTRAINT FK_8A4229A6B03A8386');
        $this->addSql('DROP TABLE application_documents');
        $this->addSql('DROP TABLE interviews');
        $this->addSql('DROP TABLE job_applications');
        $this->addSql('DROP TABLE job_offers');
    }
}
