<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commandes en plusieurs devises : la commande garde sa devise, le taux appliqué et les montants figés dans cette devise.
 *
 * Les montants historiques (subtotal, delivery_fee, total) restent en Ariary : c'est ce qui est débité. Les nouvelles colonnes
 * sont vides pour les commandes déjà enregistrées, qui sont toutes en Ariary (devise « MGA » par défaut).
 */
final class Version20261102000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commandes : devise, taux figé et montants dans la devise du client (orders, order_items).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_items ADD unit_price_charged INT DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD currency VARCHAR(3) DEFAULT \'MGA\' NOT NULL');
        $this->addSql('ALTER TABLE orders ADD exchange_rate NUMERIC(18, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD subtotal_charged INT DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD delivery_fee_charged INT DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD total_charged INT DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN orders.currency IS \'Devise de la commande ; les montants subtotal/delivery_fee/total restent en Ariary\'');
        $this->addSql('COMMENT ON COLUMN orders.exchange_rate IS \'Ariary pour 1 unité de la devise, figé à la commande\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_items DROP unit_price_charged');
        $this->addSql('ALTER TABLE orders DROP currency');
        $this->addSql('ALTER TABLE orders DROP exchange_rate');
        $this->addSql('ALTER TABLE orders DROP subtotal_charged');
        $this->addSql('ALTER TABLE orders DROP delivery_fee_charged');
        $this->addSql('ALTER TABLE orders DROP total_charged');
    }
}
