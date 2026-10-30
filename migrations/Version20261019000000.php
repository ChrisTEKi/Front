<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Anti-doublon de commande : un « jeton d'idempotence » par tentative de paiement.
 *
 * Problème résolu : un double-clic sur « Payer », un réseau qui coupe puis un renvoi automatique du navigateur… ne
 * doivent JAMAIS créer deux commandes (et donc deux paiements). Le navigateur génère un jeton aléatoire par
 * tentative ; la base garantit qu'un même client ne peut pas enregistrer deux commandes avec le même jeton.
 *
 * PostgreSQL considère que deux NULL sont différents : les commandes sans jeton (créées autrement) ne sont pas concernées.
 */
final class Version20261019000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commandes : jeton d\'idempotence (anti-doublon) avec unicité par client.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ADD idempotency_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_orders_client_idempotency ON orders (client_id, idempotency_key)');
        $this->addSql('COMMENT ON COLUMN orders.idempotency_key IS \'Jeton anti-doublon généré par le navigateur à chaque tentative de paiement (unique par client)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_orders_client_idempotency');
        $this->addSql('ALTER TABLE orders DROP idempotency_key');
    }
}
