<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Legacy migration already applied before its source was restored; preserve the existing schema.';
    }

    public function up(Schema $schema): void
    {
        // Ce fichier est un stub de compatibilité : la migration historique a déjà été appliquée.
        // Aucune création de table ni modification du schéma n'est réalisée ici pour éviter les doublons.
    }

    public function down(Schema $schema): void
    {
        // Ce stub ne doit pas supprimer le schéma existant, car il sert uniquement à préserver la trace Doctrine.
        // Les structures déjà présentes en base doivent rester intactes.
    }
}