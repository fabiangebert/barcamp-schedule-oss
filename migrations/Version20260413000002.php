<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add guest_id to documentation table
 */
final class Version20260413000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add guest_id column to documentation table';
    }

    public function up(Schema $schema): void
    {
        $documentation = $schema->getTable('documentation');
        $documentation->addColumn('guest_id', 'string', ['length' => 64, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $documentation = $schema->getTable('documentation');
        $documentation->dropColumn('guest_id');
    }
}
