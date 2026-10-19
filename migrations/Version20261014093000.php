<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the initial entities used by the administrator dashboard.';
    }

    public function up(Schema $schema): void
    {
        // Comptes affichés dans l'annuaire administrateur.
        $this->addSql('CREATE TABLE app_user (id SERIAL NOT NULL, full_name VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(32) DEFAULT NULL, roles JSON NOT NULL, email_verified BOOLEAN NOT NULL, phone_verified BOOLEAN NOT NULL, two_factor_enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_email ON app_user (email)');

        // Candidatures vendeurs et fournisseurs à examiner par l'administration.
        $this->addSql('CREATE TABLE vendor_application (id SERIAL NOT NULL, type VARCHAR(24) NOT NULL, company_name VARCHAR(180) NOT NULL, contact_name VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(32) NOT NULL, status VARCHAR(24) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');

        // Catalogue des produits, avec un lien facultatif vers leur vendeur.
        $this->addSql('CREATE TABLE product (id SERIAL NOT NULL, vendor_id INT DEFAULT NULL, name VARCHAR(180) NOT NULL, sku VARCHAR(80) DEFAULT NULL, price INT NOT NULL, stock INT NOT NULL, status VARCHAR(24) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_product_vendor ON product (vendor_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_sku ON product (sku)');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT fk_product_vendor FOREIGN KEY (vendor_id) REFERENCES vendor_application (id) ON DELETE SET NULL');

        // Commandes et lien facultatif vers le compte client.
        $this->addSql('CREATE TABLE customer_order (id SERIAL NOT NULL, customer_id INT DEFAULT NULL, order_number VARCHAR(40) NOT NULL, total_amount INT NOT NULL, payment_method VARCHAR(32) NOT NULL, status VARCHAR(24) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_customer_order_number ON customer_order (order_number)');
        $this->addSql('CREATE INDEX idx_customer_order_customer ON customer_order (customer_id)');
        $this->addSql('ALTER TABLE customer_order ADD CONSTRAINT fk_customer_order_customer FOREIGN KEY (customer_id) REFERENCES app_user (id) ON DELETE SET NULL');

        // Reversements vendeurs et période comptable correspondante.
        $this->addSql('CREATE TABLE vendor_payout (id SERIAL NOT NULL, vendor_id INT NOT NULL, gross_sales INT NOT NULL, commission_amount INT NOT NULL, plan_fee INT NOT NULL, net_amount INT NOT NULL, status VARCHAR(24) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_vendor_payout_vendor ON vendor_payout (vendor_id)');
        $this->addSql('ALTER TABLE vendor_payout ADD CONSTRAINT fk_vendor_payout_vendor FOREIGN KEY (vendor_id) REFERENCES vendor_application (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // Supprimer d'abord les tables dépendantes pour respecter les clés étrangères.
        $this->addSql('DROP TABLE vendor_payout');
        $this->addSql('DROP TABLE customer_order');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE vendor_application');
        $this->addSql('DROP TABLE app_user');
    }
}