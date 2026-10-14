<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add is_public column to documentation table
 */
final class Version20260413172000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_public column to documentation table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('documentation');
        $table->addColumn('is_public', 'boolean', ['default' => 0]);

        // Update existing documentation:
        // 1. Session documentation is public (timeslot != "event" OR room != "event")
        // 2. User-contributed feedback is public (user_id IS NOT NULL)
        // Using addSql to update existing data since the column now exists.
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement(
            'UPDATE documentation SET is_public = TRUE 
             WHERE (timeslot != \'event\' OR room != \'event\') OR (user_id IS NOT NULL)'
        );
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('documentation');
        $table->dropColumn('is_public');
    }
}
