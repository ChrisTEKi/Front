<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Taux de change : table `exchange_rates` (historique des taux, Ariary pour 1 unité de la devise).
 *
 * Aucun taux n'est inséré ici : ils sont récupérés par `app:rates:refresh` ou saisis par un administrateur.
 */
final class Version20261102000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Taux de change : historique des taux EUR/USD contre l\'Ariary.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE exchange_rates (id UUID NOT NULL, currency VARCHAR(3) NOT NULL, rate NUMERIC(18, 6) NOT NULL, source VARCHAR(8) NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, valid_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_exchange_rates_currency_created ON exchange_rates (currency, created_at)');
        $this->addSql('CREATE INDEX IDX_5AE3E774B03A8386 ON exchange_rates (created_by_id)');
        $this->addSql('COMMENT ON TABLE exchange_rates IS \'Historique des taux de change (Ariary pour 1 unité de la devise).\'');
        $this->addSql('COMMENT ON COLUMN exchange_rates.rate IS \'Ariary pour 1 unité de la devise\'');
        $this->addSql('ALTER TABLE exchange_rates ADD CONSTRAINT FK_5AE3E774B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exchange_rates DROP CONSTRAINT FK_5AE3E774B03A8386');
        $this->addSql('DROP TABLE exchange_rates');
    }
}
