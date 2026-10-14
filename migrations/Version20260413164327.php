<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Update documentation table schema and add name to users
 */
final class Version20260413164327 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update documentation table: drop user_email/user_name, add user_id/guest_name. Add name to users.';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('users');
        if (!$users->hasColumn('name')) {
            $users->addColumn('name', 'string', ['length' => 255, 'notnull' => false]);
        }

        $documentation = $schema->getTable('documentation');
        if (!$documentation->hasColumn('user_id')) {
            $documentation->addColumn('user_id', 'integer', ['notnull' => false]);
        }
        if (!$documentation->hasColumn('guest_name')) {
            $documentation->addColumn('guest_name', 'string', ['length' => 255, 'notnull' => false]);
        }

        if ($documentation->hasColumn('user_email')) {
            $documentation->dropColumn('user_email');
        }
        if ($documentation->hasColumn('user_name')) {
            $documentation->dropColumn('user_name');
        }
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement('UPDATE users SET name = email WHERE name IS NULL');
    }

    public function down(Schema $schema): void
    {
        $documentation = $schema->getTable('documentation');
        $documentation->addColumn('user_email', 'string', ['length' => 255, 'notnull' => false]);
        $documentation->addColumn('user_name', 'string', ['length' => 255, 'notnull' => false]);
        $documentation->dropColumn('user_id');
        $documentation->dropColumn('guest_name');

        $users = $schema->getTable('users');
        $users->dropColumn('name');
    }
}
