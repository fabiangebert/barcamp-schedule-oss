<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial baseline migration
 */
final class Version20260401000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial baseline migration';
    }

    public function up(Schema $schema): void
    {
        // Users table
        $users = $schema->createTable('users');
        $users->addColumn('id', 'integer', ['autoincrement' => true]);
        $users->addColumn('email', 'string', ['length' => 255]);
        $users->addColumn('role', 'string', ['length' => 20, 'default' => 'user']);
        $users->addColumn('magic_token', 'string', ['length' => 64, 'notnull' => false]);
        $users->addColumn('token_expires_at', 'datetime', ['notnull' => false]);
        $users->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);
        $users->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $users->addUniqueIndex(['email']);

        // Schedules table
        $schedules = $schema->createTable('schedules');
        $schedules->addColumn('id', 'integer', ['autoincrement' => true]);
        $schedules->addColumn('name', 'string', ['length' => 255]);
        $schedules->addColumn('hashed_id', 'string', ['length' => 64]);
        $schedules->addColumn('data', 'text');
        $schedules->addColumn('version', 'integer', ['default' => 1]);
        $schedules->addColumn('date', 'string', ['length' => 10, 'notnull' => false]);
        $schedules->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);
        $schedules->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);
        $schedules->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $schedules->addUniqueIndex(['name']);
        $schedules->addUniqueIndex(['hashed_id']);

        // Share links table
        $shareLinks = $schema->createTable('share_links');
        $shareLinks->addColumn('id', 'integer', ['autoincrement' => true]);
        $shareLinks->addColumn('schedule_id', 'string', ['length' => 64]);
        $shareLinks->addColumn('token', 'string', ['length' => 64]);
        $shareLinks->addColumn('created_by', 'string', ['length' => 255]);
        $shareLinks->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);
        $shareLinks->addColumn('expires_at', 'datetime');
        $shareLinks->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $shareLinks->addUniqueIndex(['token']);

        // Documentation table
        $documentation = $schema->createTable('documentation');
        $documentation->addColumn('id', 'integer', ['autoincrement' => true]);
        $documentation->addColumn('schedule_id', 'string', ['length' => 64]);
        $documentation->addColumn('timeslot', 'string', ['length' => 255]);
        $documentation->addColumn('room', 'string', ['length' => 255]);
        $documentation->addColumn('user_email', 'string', ['length' => 255]);
        $documentation->addColumn('user_name', 'string', ['length' => 255, 'notnull' => false]);
        $documentation->addColumn('session_title', 'string', ['length' => 255, 'notnull' => false]);
        $documentation->addColumn('content', 'text', ['notnull' => false]);
        $documentation->addColumn('image_path', 'string', ['length' => 255, 'notnull' => false]);
        $documentation->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);
        $documentation->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());

        // Documentation images table
        $docImages = $schema->createTable('documentation_images');
        $docImages->addColumn('id', 'integer', ['autoincrement' => true]);
        $docImages->addColumn('documentation_id', 'integer');
        $docImages->addColumn('image_path', 'string', ['length' => 255]);
        $docImages->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
    }

    public function postUp(Schema $schema): void
    {
        $adminEmail = getenv('ADMIN_EMAIL');
        if ($adminEmail) {
            $this->connection->insert('users', [
                'email' => $adminEmail,
                'role' => 'admin'
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('documentation_images');
        $schema->dropTable('documentation');
        $schema->dropTable('share_links');
        $schema->dropTable('schedules');
        $schema->dropTable('users');
    }
}
