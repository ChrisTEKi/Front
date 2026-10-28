<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retire the legacy dashboard schema; the application tables already exist.';
    }

    public function up(Schema $schema): void
    {
        // The previous draft described a different database schema and is intentionally retired.
    }

    public function down(Schema $schema): void
    {
        // No schema changes were applied by this retired migration.
    }
}