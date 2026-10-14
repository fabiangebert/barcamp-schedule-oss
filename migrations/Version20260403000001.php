<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add rating to documentation table
 */
final class Version20260403000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rating column to documentation table';
    }

    public function up(Schema $schema): void
    {
        $documentation = $schema->getTable('documentation');
        $documentation->addColumn('rating', 'integer', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $documentation = $schema->getTable('documentation');
        $documentation->dropColumn('rating');
    }
}
