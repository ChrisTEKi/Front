<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MIGRATION INITIALE — crée tout le schéma PostgreSQL de GoBuy (authentification, comptes, commandes, audit).
 *
 * Lecture rapide du schéma :
 *   users ──┬── supplier_profiles   (1-1)  dossier fournisseur + approbation
 *           ├── admin_profiles      (1-1)  ─── admin_roles (profil de droits)
 *           ├── one_time_codes      (1-N)  codes OTP hachés
 *           ├── social_identities   (1-N)  Google / Apple
 *           ├── mobile_money_accounts (1-N) numéros Mobile Money vérifiés
 *           ├── addresses           (1-N)  adresses de livraison
 *           └── orders              (1-N)  ── order_items / order_tracking_events
 *   audit_logs : journal d'audit indépendant (sans clé étrangère).
 *
 * Types PostgreSQL utilisés : UUID (identifiants v7), JSONB (rôles, droits, adresses), TIMESTAMPTZ (dates en UTC),
 * index unique PARTIEL (un téléphone VÉRIFIÉ = un seul compte).
 *
 * Les commentaires « COMMENT ON … » documentent les tables et colonnes directement dans la base
 * (visibles avec `\d+ users` dans psql).
 */
final class Version20261018000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial GoBuy : comptes, vérifications OTP, admins & droits, commandes & suivi, journal d\'audit.';
    }

    public function up(Schema $schema): void
    {
        // ----------------------------------------------------------------------
        // users — comptes (clients, fournisseurs, admins). Table centrale : toutes les autres s'y rattachent.
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) DEFAULT NULL, full_name VARCHAR(150) NOT NULL, phone VARCHAR(20) DEFAULT NULL, email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, phone_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, account_type VARCHAR(16) NOT NULL, roles JSONB NOT NULL, status VARCHAR(20) NOT NULL, locale VARCHAR(5) DEFAULT \'fr\' NOT NULL, two_factor_email_enabled BOOLEAN DEFAULT false NOT NULL, two_factor_sms_enabled BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, password_changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, deletion_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, deletion_scheduled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_users_account_type ON users (account_type)');
        $this->addSql('CREATE INDEX idx_users_deletion_scheduled_at ON users (deletion_scheduled_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_verified_phone ON users (phone) WHERE (phone_verified_at IS NOT NULL)');
        $this->addSql('COMMENT ON TABLE users IS \'Comptes utilisateurs : clients, fournisseurs et administrateurs (un seul compte par e-mail).\'');
        $this->addSql('COMMENT ON COLUMN users.email IS \'Adresse e-mail en minuscules = identifiant de connexion\'');
        $this->addSql('COMMENT ON COLUMN users.password IS \'Mot de passe haché Argon2id (jamais en clair). NULL = compte créé via Google/Apple\'');
        $this->addSql('COMMENT ON COLUMN users.phone IS \'Mobile au format E.164 (+261…). Obligatoire et vérifié avant la 1re commande\'');
        $this->addSql('COMMENT ON COLUMN users.email_verified_at IS \'Date de validation du code OTP e-mail (NULL = non vérifié)\'');
        $this->addSql('COMMENT ON COLUMN users.phone_verified_at IS \'Date de validation du code OTP SMS (NULL = non vérifié)\'');
        $this->addSql('COMMENT ON COLUMN users.account_type IS \'client | supplier | admin\'');
        $this->addSql('COMMENT ON COLUMN users.roles IS \'Rôles Symfony Security (ROLE_CLIENT, ROLE_SUPPLIER, ROLE_ADMIN, ROLE_SUPER_ADMIN)\'');
        $this->addSql('COMMENT ON COLUMN users.status IS \'active | suspended | pending_deletion\'');
        $this->addSql('COMMENT ON COLUMN users.two_factor_email_enabled IS \'2FA par code e-mail à chaque connexion\'');
        $this->addSql('COMMENT ON COLUMN users.two_factor_sms_enabled IS \'2FA par code SMS à chaque connexion (téléphone vérifié requis)\'');
        $this->addSql('COMMENT ON COLUMN users.deletion_scheduled_at IS \'Suppression définitive programmée (demande + 30 jours), exécutée par app:accounts:purge\'');

        // ----------------------------------------------------------------------
        // supplier_profiles — dossier d'un fournisseur + statut d'approbation (1-1 avec users).
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE supplier_profiles (company_name VARCHAR(150) NOT NULL, company_address VARCHAR(255) NOT NULL, company_description TEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, submitted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, reviewed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, user_id UUID NOT NULL, reviewed_by_id UUID DEFAULT NULL, PRIMARY KEY (user_id))');
        $this->addSql('CREATE INDEX idx_supplier_status ON supplier_profiles (status)');
        $this->addSql('CREATE INDEX IDX_6B9EDB1FC6B21F1 ON supplier_profiles (reviewed_by_id)');
        $this->addSql('COMMENT ON TABLE supplier_profiles IS \'Dossier d\'\'un fournisseur (société, adresse) + statut d\'\'approbation par un admin. 1 ligne par compte fournisseur.\'');
        $this->addSql('COMMENT ON COLUMN supplier_profiles.status IS \'pending | approved | rejected | suspended\'');

        // ----------------------------------------------------------------------
        // admin_roles — profils d'administrateurs (ensembles de droits).
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE admin_roles (id UUID NOT NULL, code VARCHAR(50) NOT NULL, label VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, permissions JSONB NOT NULL, is_system BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_admin_roles_code ON admin_roles (code)');
        $this->addSql('COMMENT ON TABLE admin_roles IS \'Profils d\'\'administrateurs (Super Admin, Admin Finance, Admin Compta…) = ensembles de droits fins.\'');
        $this->addSql('COMMENT ON COLUMN admin_roles.permissions IS \'Droits du profil (valeurs de l\'\'enum Permission)\'');
        $this->addSql('COMMENT ON COLUMN admin_roles.is_system IS \'Profil système : non modifiable / non supprimable\'');

        // ----------------------------------------------------------------------
        // admin_profiles — données d'un compte admin : profil + droits ajoutés/retirés (1-1 avec users).
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE admin_profiles (extra_permissions JSONB NOT NULL, denied_permissions JSONB NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, admin_role_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (user_id))');
        $this->addSql('CREATE INDEX IDX_1F908236123FA025 ON admin_profiles (admin_role_id)');
        $this->addSql('CREATE INDEX IDX_1F908236B03A8386 ON admin_profiles (created_by_id)');
        $this->addSql('COMMENT ON TABLE admin_profiles IS \'Données d\'\'un compte administrateur : profil de droits + droits ajoutés/retirés individuellement.\'');
        $this->addSql('COMMENT ON COLUMN admin_profiles.extra_permissions IS \'Droits ajoutés individuellement en plus du profil\'');
        $this->addSql('COMMENT ON COLUMN admin_profiles.denied_permissions IS \'Droits retirés individuellement (restrictions) : l\'\'emportent sur le profil\'');

        // ----------------------------------------------------------------------
        // one_time_codes — codes OTP hachés (e-mail, SMS, 2FA, mot de passe oublié, Mobile Money, invitation admin).
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE one_time_codes (id UUID NOT NULL, purpose VARCHAR(32) NOT NULL, channel VARCHAR(8) NOT NULL, target VARCHAR(255) NOT NULL, code_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, attempts SMALLINT DEFAULT 0 NOT NULL, max_attempts SMALLINT NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_otp_user_purpose ON one_time_codes (user_id, purpose)');
        $this->addSql('CREATE INDEX idx_otp_expires_at ON one_time_codes (expires_at)');
        $this->addSql('CREATE INDEX IDX_E705EE4BA76ED395 ON one_time_codes (user_id)');
        $this->addSql('COMMENT ON TABLE one_time_codes IS \'Codes à usage unique (OTP) : vérification e-mail/SMS, 2FA, mot de passe oublié, numéro Mobile Money, invitation admin. Stockés HACHÉS.\'');
        $this->addSql('COMMENT ON COLUMN one_time_codes.target IS \'Adresse e-mail ou numéro E.164 auquel le code a été envoyé\'');
        $this->addSql('COMMENT ON COLUMN one_time_codes.code_hash IS \'Empreinte HMAC-SHA256 du code (le code en clair n\'\'est jamais stocké)\'');
        $this->addSql('COMMENT ON COLUMN one_time_codes.consumed_at IS \'Date d\'\'utilisation (ou d\'\'invalidation) : un code ne sert qu\'\'une fois\'');
        $this->addSql('COMMENT ON COLUMN one_time_codes.attempts IS \'Saisies incorrectes déjà faites\'');

        // ----------------------------------------------------------------------
        // social_identities — identités Google / Apple liées à un compte.
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE social_identities (id UUID NOT NULL, provider VARCHAR(16) NOT NULL, subject VARCHAR(255) NOT NULL, email_at_link VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_social_user ON social_identities (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_provider_subject ON social_identities (provider, subject)');
        $this->addSql('COMMENT ON TABLE social_identities IS \'Identités Google / Apple rattachées à un compte (connexion rapide). Clé : (fournisseur, sub).\'');

        // ----------------------------------------------------------------------
        // mobile_money_accounts — numéros Mobile Money vérifiés par SMS.
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE mobile_money_accounts (id UUID NOT NULL, carrier VARCHAR(16) NOT NULL, msisdn VARCHAR(20) NOT NULL, verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mm_user_msisdn ON mobile_money_accounts (user_id, msisdn)');
        $this->addSql('CREATE INDEX IDX_5C7AB399A76ED395 ON mobile_money_accounts (user_id)');
        $this->addSql('COMMENT ON TABLE mobile_money_accounts IS \'Numéros Mobile Money des clients (MVola, Orange Money, Airtel Money) et état de leur vérification par SMS.\'');

        // ----------------------------------------------------------------------
        // addresses — adresses de livraison enregistrées.
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE addresses (id UUID NOT NULL, full_name VARCHAR(150) NOT NULL, phone VARCHAR(20) NOT NULL, street VARCHAR(255) NOT NULL, city VARCHAR(100) NOT NULL, postal_code VARCHAR(10) DEFAULT NULL, instructions TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_addresses_user ON addresses (user_id)');
        $this->addSql('COMMENT ON TABLE addresses IS \'Adresses de livraison enregistrées par les clients.\'');

        // ----------------------------------------------------------------------
        // orders — commandes (copie figée des coordonnées client, conservée après suppression du compte).
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE orders (id UUID NOT NULL, order_number VARCHAR(20) NOT NULL, customer_name VARCHAR(150) DEFAULT NULL, customer_email VARCHAR(180) DEFAULT NULL, customer_phone VARCHAR(20) DEFAULT NULL, status VARCHAR(20) NOT NULL, subtotal INT NOT NULL, delivery_fee INT NOT NULL, total INT NOT NULL, payment_method VARCHAR(20) NOT NULL, payment_status VARCHAR(16) NOT NULL, payment_carrier VARCHAR(16) DEFAULT NULL, payment_msisdn VARCHAR(20) DEFAULT NULL, installments_count SMALLINT DEFAULT 1 NOT NULL, shipping_address JSONB NOT NULL, courier_name VARCHAR(150) DEFAULT NULL, courier_phone VARCHAR(20) DEFAULT NULL, locale VARCHAR(5) DEFAULT \'fr\' NOT NULL, confirmation_email_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, client_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_orders_client ON orders (client_id)');
        $this->addSql('CREATE INDEX idx_orders_status ON orders (status)');
        $this->addSql('CREATE INDEX idx_orders_created_at ON orders (created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_orders_number ON orders (order_number)');
        $this->addSql('COMMENT ON TABLE orders IS \'Commandes des clients. Contient une copie figée des coordonnées client + adresse de livraison (anonymisée à la suppression du compte).\'');
        $this->addSql('COMMENT ON COLUMN orders.order_number IS \'Numéro public, ex. GB-2026-482915\'');
        $this->addSql('COMMENT ON COLUMN orders.subtotal IS \'Total des articles en Ariary\'');
        $this->addSql('COMMENT ON COLUMN orders.total IS \'Montant total en Ariary = subtotal + delivery_fee\'');
        $this->addSql('COMMENT ON COLUMN orders.installments_count IS \'1 = comptant, 3 ou 4 = paiement fractionné sans frais\'');
        $this->addSql('COMMENT ON COLUMN orders.shipping_address IS \'Copie JSON de l\'\'adresse de livraison\'');
        $this->addSql('COMMENT ON COLUMN orders.confirmation_email_sent_at IS \'NULL = e-mail de confirmation pas encore parti (réessayé par app:orders:send-confirmations)\'');

        // ----------------------------------------------------------------------
        // order_items — lignes de commande (nom/prix copiés).
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE order_items (id UUID NOT NULL, product_ref VARCHAR(64) DEFAULT NULL, product_name VARCHAR(255) NOT NULL, unit_price INT NOT NULL, quantity SMALLINT NOT NULL, order_id UUID NOT NULL, supplier_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_order_items_order ON order_items (order_id)');
        $this->addSql('CREATE INDEX IDX_62809DB02ADD6D8C ON order_items (supplier_id)');
        $this->addSql('COMMENT ON TABLE order_items IS \'Lignes de commande : nom et prix du produit copiés au moment de l\'\'achat.\'');

        // ----------------------------------------------------------------------
        // order_tracking_events — historique de suivi des colis.
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE order_tracking_events (id UUID NOT NULL, status VARCHAR(20) NOT NULL, description TEXT DEFAULT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, order_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_tracking_order ON order_tracking_events (order_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_3002175A8D9F6D38 ON order_tracking_events (order_id)');
        $this->addSql('CREATE INDEX IDX_3002175AB03A8386 ON order_tracking_events (created_by_id)');
        $this->addSql('COMMENT ON TABLE order_tracking_events IS \'Historique de suivi d\'\'un colis : une ligne par changement d\'\'étape.\'');

        // ----------------------------------------------------------------------
        // audit_logs — journal d'audit (sans clé étrangère : survit aux comptes supprimés).
        // ----------------------------------------------------------------------
        $this->addSql('CREATE TABLE audit_logs (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, actor_id UUID DEFAULT NULL, actor_email VARCHAR(180) DEFAULT NULL, action VARCHAR(64) NOT NULL, target_type VARCHAR(32) DEFAULT NULL, target_id VARCHAR(64) DEFAULT NULL, metadata JSONB NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_created_at ON audit_logs (created_at)');
        $this->addSql('CREATE INDEX idx_audit_actor ON audit_logs (actor_id)');
        $this->addSql('CREATE INDEX idx_audit_action ON audit_logs (action)');
        $this->addSql('COMMENT ON TABLE audit_logs IS \'Journal d\'\'audit : qui a fait quoi, quand, d\'\'où. Pas de clé étrangère : survit à la suppression des comptes.\'');
        $this->addSql('COMMENT ON COLUMN audit_logs.actor_id IS \'Identifiant de l\'\'auteur (sans clé étrangère : survit à la suppression du compte)\'');
        $this->addSql('COMMENT ON COLUMN audit_logs.metadata IS \'Détails JSON (jamais de secret)\'');

        // ----------------------------------------------------------------------
        // Clés étrangères (ajoutées en dernier : toutes les tables existent alors).
        //  ON DELETE CASCADE  → la ligne disparaît avec le compte (codes OTP, adresses, identités sociales…)
        //  ON DELETE SET NULL → la ligne est conservée mais détachée (commandes d'un compte supprimé, auteur d'un événement…)
        //  ON DELETE RESTRICT → suppression refusée tant que la ligne est utilisée (profil admin encore assigné)
        // ----------------------------------------------------------------------
        $this->addSql('ALTER TABLE addresses ADD CONSTRAINT FK_6FCA7516A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE admin_profiles ADD CONSTRAINT FK_1F908236A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE admin_profiles ADD CONSTRAINT FK_1F908236123FA025 FOREIGN KEY (admin_role_id) REFERENCES admin_roles (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE admin_profiles ADD CONSTRAINT FK_1F908236B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mobile_money_accounts ADD CONSTRAINT FK_5C7AB399A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE one_time_codes ADD CONSTRAINT FK_E705EE4BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE order_items ADD CONSTRAINT FK_62809DB08D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE order_items ADD CONSTRAINT FK_62809DB02ADD6D8C FOREIGN KEY (supplier_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE order_tracking_events ADD CONSTRAINT FK_3002175A8D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE order_tracking_events ADD CONSTRAINT FK_3002175AB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE19EB6921 FOREIGN KEY (client_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE social_identities ADD CONSTRAINT FK_346A9E2AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE supplier_profiles ADD CONSTRAINT FK_6B9EDB1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE supplier_profiles ADD CONSTRAINT FK_6B9EDB1FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');

        // ----------------------------------------------------------------------
        // Données de référence : le profil système « Super Admin » (non modifiable, non supprimable).
        // Il porte TOUS les droits. Le compte Super Admin lui-même n'est PAS créé ici :
        // il l'est uniquement en ligne de commande (php bin/console app:admin:create-super).
        // ----------------------------------------------------------------------
        $this->addSql(<<<'SQL'
            INSERT INTO admin_roles (id, code, label, description, permissions, is_system, created_at, updated_at)
            VALUES (
                '01920000-0000-7000-8000-000000000001',
                'super_admin',
                'Super Admin',
                'Tous les droits. Seul profil autorisé à créer des administrateurs et à leur attribuer des droits.',
                '["users.view","users.suspend","users.export","suppliers.view","suppliers.approve","suppliers.export","orders.view","orders.update_tracking","finance.view","finance.manage","audit.view","admins.view","admins.manage","admins.export"]'::jsonb,
                TRUE,
                NOW(),
                NOW()
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Suppression dans l'ordre inverse de la création : d'abord les clés étrangères, puis les tables.
        $this->addSql('ALTER TABLE addresses DROP CONSTRAINT FK_6FCA7516A76ED395');
        $this->addSql('ALTER TABLE admin_profiles DROP CONSTRAINT FK_1F908236A76ED395');
        $this->addSql('ALTER TABLE admin_profiles DROP CONSTRAINT FK_1F908236123FA025');
        $this->addSql('ALTER TABLE admin_profiles DROP CONSTRAINT FK_1F908236B03A8386');
        $this->addSql('ALTER TABLE mobile_money_accounts DROP CONSTRAINT FK_5C7AB399A76ED395');
        $this->addSql('ALTER TABLE one_time_codes DROP CONSTRAINT FK_E705EE4BA76ED395');
        $this->addSql('ALTER TABLE order_items DROP CONSTRAINT FK_62809DB08D9F6D38');
        $this->addSql('ALTER TABLE order_items DROP CONSTRAINT FK_62809DB02ADD6D8C');
        $this->addSql('ALTER TABLE order_tracking_events DROP CONSTRAINT FK_3002175A8D9F6D38');
        $this->addSql('ALTER TABLE order_tracking_events DROP CONSTRAINT FK_3002175AB03A8386');
        $this->addSql('ALTER TABLE orders DROP CONSTRAINT FK_E52FFDEE19EB6921');
        $this->addSql('ALTER TABLE social_identities DROP CONSTRAINT FK_346A9E2AA76ED395');
        $this->addSql('ALTER TABLE supplier_profiles DROP CONSTRAINT FK_6B9EDB1A76ED395');
        $this->addSql('ALTER TABLE supplier_profiles DROP CONSTRAINT FK_6B9EDB1FC6B21F1');
        $this->addSql('DROP TABLE addresses');
        $this->addSql('DROP TABLE admin_profiles');
        $this->addSql('DROP TABLE admin_roles');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE mobile_money_accounts');
        $this->addSql('DROP TABLE one_time_codes');
        $this->addSql('DROP TABLE order_items');
        $this->addSql('DROP TABLE order_tracking_events');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE social_identities');
        $this->addSql('DROP TABLE supplier_profiles');
        $this->addSql('DROP TABLE users');
    }
}
