<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catalogue de produits (table `products`).
 *
 * - `owner_id` : le fournisseur propriétaire ; NULL = produit vendu par GoBuy lui-même. La suppression d'un compte
 *   fournisseur supprime son catalogue (ON DELETE CASCADE) ; les commandes passées gardent leur propre copie des lignes.
 * - `price_mga` : prix de base en Ariary ; `price_overrides` (jsonb) : prix spécifiques par devise en unités mineures.
 * - Modération (réservée aux administrateurs) : retrait (statut `removed` + motif), signalement, mise en avant. Les
 *   administrateurs qui ont posé ces marques sont référencés avec ON DELETE SET NULL (la marque reste si l'admin disparaît).
 */
final class Version20261031000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalogue de produits : table products (propriétaire fournisseur ou GoBuy, prix par devise, modération).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE products (id UUID NOT NULL, reference VARCHAR(64) DEFAULT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, category VARCHAR(64) DEFAULT NULL, price_mga INT NOT NULL, price_overrides JSONB NOT NULL, status VARCHAR(16) NOT NULL, removed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, removed_reason VARCHAR(500) DEFAULT NULL, flagged_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, flag_reason VARCHAR(500) DEFAULT NULL, featured_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, featured_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, owner_id UUID DEFAULT NULL, removed_by_id UUID DEFAULT NULL, flagged_by_id UUID DEFAULT NULL, featured_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_products_owner ON products (owner_id)');
        $this->addSql('CREATE INDEX idx_products_status ON products (status)');
        $this->addSql('CREATE INDEX idx_products_featured ON products (featured_at)');
        $this->addSql('CREATE INDEX IDX_B3BA5A5A2BD701DA ON products (removed_by_id)');
        $this->addSql('CREATE INDEX IDX_B3BA5A5A60B67306 ON products (flagged_by_id)');
        $this->addSql('CREATE INDEX IDX_B3BA5A5AF3AEDB9E ON products (featured_by_id)');
        $this->addSql('COMMENT ON TABLE products IS \'Catalogue : produits des fournisseurs et de GoBuy (owner NULL).\'');
        $this->addSql('COMMENT ON COLUMN products.price_mga IS \'Prix unitaire de base en Ariary\'');
        $this->addSql('COMMENT ON COLUMN products.price_overrides IS \'Prix fixés par le vendeur pour une devise (unités mineures)\'');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A2BD701DA FOREIGN KEY (removed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A60B67306 FOREIGN KEY (flagged_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5AF3AEDB9E FOREIGN KEY (featured_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE products');
    }
}
