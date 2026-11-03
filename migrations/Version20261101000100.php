<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sanctions : bannissement définitif et gel des fonds d'un compte (colonnes de `users`), remboursements (colonnes de
 * `orders` et `order_items`) et liste noire (`blacklist_entries`, volontairement sans lien vers un compte : elle survit à sa suppression).
 */
final class Version20261101000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sanctions : bannissement, gel des fonds, remboursements des acheteurs et liste noire.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE blacklist_entries (id UUID NOT NULL, kind VARCHAR(8) NOT NULL, value VARCHAR(255) NOT NULL, reason VARCHAR(500) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_blacklist_created_at ON blacklist_entries (created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_blacklist_kind_value ON blacklist_entries (kind, value)');
        $this->addSql('CREATE INDEX IDX_98749198B03A8386 ON blacklist_entries (created_by_id)');
        $this->addSql('COMMENT ON TABLE blacklist_entries IS \'Liste noire : e-mails, téléphones, noms, raisons sociales et adresses IP interdits d\'\'inscription.\'');
        $this->addSql('COMMENT ON COLUMN blacklist_entries.value IS \'Valeur normalisée\'');
        $this->addSql('ALTER TABLE blacklist_entries ADD CONSTRAINT FK_98749198B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE order_items ADD refunded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD refunded_amount INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE orders ADD refunded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN orders.refunded_amount IS \'Montant remboursé (Ariary) suite à une décision de l\'\'équipe ; jamais supérieur au total\'');
        $this->addSql('ALTER TABLE users ADD banned_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD ban_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD funds_frozen_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD funds_frozen_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD banned_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD funds_frozen_by_id UUID DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN users.funds_frozen_at IS \'Gel conservatoire : plus de vente ni de versement (fournisseur), plus de commande (client)\'');
        $this->addSql('COMMENT ON COLUMN users.status IS \'active | suspended | pending_deletion | banned\'');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9386B8E7 FOREIGN KEY (banned_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9811C7621 FOREIGN KEY (funds_frozen_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1483A5E9386B8E7 ON users (banned_by_id)');
        $this->addSql('CREATE INDEX IDX_1483A5E9811C7621 ON users (funds_frozen_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blacklist_entries DROP CONSTRAINT FK_98749198B03A8386');
        $this->addSql('DROP TABLE blacklist_entries');
        $this->addSql('ALTER TABLE order_items DROP refunded_at');
        $this->addSql('ALTER TABLE orders DROP refunded_amount');
        $this->addSql('ALTER TABLE orders DROP refunded_at');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E9386B8E7');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E9811C7621');
        $this->addSql('DROP INDEX IDX_1483A5E9386B8E7');
        $this->addSql('DROP INDEX IDX_1483A5E9811C7621');
        $this->addSql('ALTER TABLE users DROP banned_at');
        $this->addSql('ALTER TABLE users DROP ban_reason');
        $this->addSql('ALTER TABLE users DROP funds_frozen_at');
        $this->addSql('ALTER TABLE users DROP funds_frozen_reason');
        $this->addSql('ALTER TABLE users DROP banned_by_id');
        $this->addSql('ALTER TABLE users DROP funds_frozen_by_id');
        $this->addSql('COMMENT ON COLUMN users.status IS \'active | suspended | pending_deletion\'');
    }
}
