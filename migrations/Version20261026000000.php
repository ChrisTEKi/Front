<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Photo de profil + carnet d'adresses de livraison.
 *
 * 1) `user_avatars` : une photo par compte (octets de l'image en `bytea`), supprimée avec le compte (ON DELETE CASCADE).
 *    `users.avatar_updated_at` dit s'il y a une photo sans lire la table des images.
 *
 * 2) `addresses` devient un vrai carnet d'adresses :
 *    - `is_default` : l'adresse proposée en premier à la commande. Un index unique PARTIEL (WHERE is_default = true)
 *      garantit au plus UNE adresse par défaut par client, même si deux requêtes arrivent en même temps ;
 *    - `label` : nom facultatif (« Maison », « Bureau ») ;
 *    - `phone` devient facultatif : une adresse saisie à l'inscription n'a pas toujours de téléphone (il est exigé, et vérifié,
 *      au moment de commander).
 *    Les adresses déjà enregistrées par les commandes passées : la plus récente de chaque client devient son adresse par défaut
 *    (c'est celle que la page de commande préremplissait déjà).
 */
final class Version20261026000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Photo de profil (user_avatars, users.avatar_updated_at) et carnet d\'adresses (addresses.is_default, label, phone facultatif).';
    }

    public function up(Schema $schema): void
    {
        // --- Photo de profil ---
        $this->addSql('ALTER TABLE users ADD avatar_updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN users.avatar_updated_at IS \'Date de la dernière photo de profil (NULL = aucune). L\'\'image est dans user_avatars\'');
        $this->addSql('CREATE TABLE user_avatars (user_id UUID NOT NULL, bytes BYTEA NOT NULL, mime_type VARCHAR(32) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('ALTER TABLE user_avatars ADD CONSTRAINT FK_USER_AVATARS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('COMMENT ON TABLE user_avatars IS \'Photo de profil (image JPEG 512x512 ré-encodée par le serveur). Supprimée avec le compte (cascade).\'');

        // --- Carnet d'adresses ---
        $this->addSql('ALTER TABLE addresses ADD is_default BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE addresses ADD label VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE addresses ALTER phone DROP NOT NULL');
        $this->addSql('COMMENT ON COLUMN addresses.is_default IS \'Adresse préremplie à la commande (une seule par client)\'');
        // La plus récente adresse de chaque client devient son adresse par défaut (DISTINCT ON garde la 1re ligne de chaque client).
        $this->addSql('UPDATE addresses SET is_default = true WHERE id IN (SELECT DISTINCT ON (user_id) id FROM addresses ORDER BY user_id, created_at DESC, id DESC)');
        $this->addSql('CREATE UNIQUE INDEX uniq_addresses_default ON addresses (user_id) WHERE (is_default = true)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_addresses_default');
        $this->addSql('ALTER TABLE addresses DROP is_default');
        $this->addSql('ALTER TABLE addresses DROP label');
        // Remettre NOT NULL suppose qu'aucune adresse n'est sans téléphone : on en met un neutre pour celles qui n'en ont pas.
        $this->addSql("UPDATE addresses SET phone = '' WHERE phone IS NULL");
        $this->addSql('ALTER TABLE addresses ALTER phone SET NOT NULL');
        $this->addSql('DROP TABLE user_avatars');
        $this->addSql('ALTER TABLE users DROP avatar_updated_at');
    }
}
