<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commissions modifiables, pièces justificatives des fournisseurs, fin du loyer « Pro » et du programme de solidarité.
 *
 *  1. commission_rates     : un taux (en %) par rayon, modifiable par l'administration. Sans ligne, le taux d'origine du code
 *                            s'applique (App\Supplier\CommissionSchedule::CATEGORIES) : aucune ligne n'est donc nécessaire au départ.
 *  2. supplier_documents   : pièce d'identité et justificatif NIF / STAT (bytea), effacés avec le compte (CASCADE).
 *  3. Droit « commissions.manage » ajouté aux profils délégués PDG et DG (le Super Admin les a tous). Les profils sont modifiables :
 *     seuls PDG et DG sont touchés, et rien n'est fait si le droit y est déjà.
 *  4. Les réponses « plan » (formule Pro), « damage » et « proofs » (programme de solidarité : dommages subis, justificatifs) des
 *     dossiers déjà déposés sont EFFACÉES : ces questions n'existent plus et ce sont des informations personnelles dont
 *     GoBuy n'a plus l'usage. Cette partie n'est pas réversible (down() ne les rétablit pas).
 */
final class Version20261108000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Taux de commission modifiables, pièces justificatives fournisseurs, retrait des réponses Pro / solidarité.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE commission_rates (category VARCHAR(40) NOT NULL, rate NUMERIC(5, 2) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_by_id UUID DEFAULT NULL, PRIMARY KEY (category))');
        $this->addSql('CREATE INDEX IDX_A95B9AF896DBBDE ON commission_rates (updated_by_id)');
        $this->addSql('COMMENT ON TABLE commission_rates IS \'Taux de commission (en %) par rayon, modifiable par un administrateur.\'');
        $this->addSql('COMMENT ON COLUMN commission_rates.category IS \'Identifiant du rayon (cat-mode, cat-elec…)\'');
        $this->addSql('COMMENT ON COLUMN commission_rates.rate IS \'Pourcentage des ventes\'');
        $this->addSql('CREATE TABLE supplier_documents (id UUID NOT NULL, kind VARCHAR(10) NOT NULL, file_name VARCHAR(150) NOT NULL, content_type VARCHAR(100) NOT NULL, size INT NOT NULL, content BYTEA NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_supplier_document_kind ON supplier_documents (user_id, kind)');
        $this->addSql('CREATE INDEX IDX_38824856A76ED395 ON supplier_documents (user_id)');
        $this->addSql('COMMENT ON TABLE supplier_documents IS \'Pièce d\'\'identité et justificatif NIF/STAT des fournisseurs (contenu en bytea).\'');
        $this->addSql('ALTER TABLE commission_rates ADD CONSTRAINT FK_A95B9AF896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE supplier_documents ADD CONSTRAINT FK_38824856A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql("UPDATE admin_roles SET permissions = permissions || '[\"commissions.manage\"]'::jsonb, updated_at = NOW() WHERE code IN ('pdg', 'dg') AND NOT (permissions @> '[\"commissions.manage\"]'::jsonb)");

        // Pas d'opérateur « ? » de jsonb ici : PDO le prendrait pour un paramètre. On retire les clés sans condition, puis on vide ce qui devient « {} ».
        $this->addSql("UPDATE supplier_profiles SET application = application - 'plan' - 'damage' - 'proofs' WHERE application IS NOT NULL");
        $this->addSql("UPDATE supplier_profiles SET application = NULL WHERE application = '{}'::jsonb");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE admin_roles SET permissions = permissions - 'commissions.manage', updated_at = NOW() WHERE code IN ('pdg', 'dg')");
        $this->addSql('ALTER TABLE commission_rates DROP CONSTRAINT FK_A95B9AF896DBBDE');
        $this->addSql('ALTER TABLE supplier_documents DROP CONSTRAINT FK_38824856A76ED395');
        $this->addSql('DROP TABLE commission_rates');
        $this->addSql('DROP TABLE supplier_documents');
    }
}
