<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create login_tokens table for magic link login rate limiting and tracking.
 */
final class Version20260716000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create login_tokens table for magic link login rate limiting and tracking.';
    }

    public function up(Schema $schema): void
    {
        $loginTokens = $schema->createTable('login_tokens');
        $loginTokens->addColumn('id', 'integer', ['autoincrement' => true]);
        $loginTokens->addColumn('email', 'string', ['length' => 255]);
        $loginTokens->addColumn('token', 'string', ['length' => 64]);
        $loginTokens->addColumn('expires_at', 'datetime');
        $loginTokens->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $loginTokens->addIndex(['email'], 'idx_login_tokens_email');
        $loginTokens->addIndex(['token'], 'idx_login_tokens_token');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('login_tokens');
    }
}
