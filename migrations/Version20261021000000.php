<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dossier de candidature complet d'un fournisseur (assistant « Devenir partenaire » en 4 étapes).
 *
 * On ajoute UNE colonne `application` (type JSONB, NULL autorisé) à la table `supplier_profiles`.
 * Pourquoi du JSONB plutôt que 15 colonnes ? Ces réponses (secteur, types de produits, logistique, programme solidaire…)
 * ne servent qu'à la LECTURE par l'administrateur qui valide le dossier : on ne filtre ni ne trie dessus.
 * Un JSON évite une migration à chaque nouvelle question du formulaire. Le contenu est nettoyé côté serveur
 * (App\Auth\SupplierApplication : seules les clés et les codes connus sont acceptés).
 *
 * Les dossiers existants gardent NULL : « candidature non détaillée » (inscriptions faites avant cette migration).
 */
final class Version20261021000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute supplier_profiles.application (JSONB) : réponses détaillées de l\'assistant « Devenir partenaire ».';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier_profiles ADD application JSONB DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN supplier_profiles.application IS \'Réponses de l\'\'assistant Devenir partenaire (codes whitelistés, nettoyées côté serveur). NULL = dossier sans détail\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier_profiles DROP application');
    }
}
