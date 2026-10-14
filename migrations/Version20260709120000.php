<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add performance indexes and cascading foreign key constraints.
 *
 * Previously tables were created with primary keys only: high-frequency
 * lookup columns (schedule_id, timeslot, room, user_id, guest_id,
 * documentation_id, created_by) lacked indexes, forcing full table scans.
 * Additionally, the absence of foreign key constraints meant deleting a
 * schedule would leave orphaned documentation and share_link rows.
 */
final class Version20260709120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes and cascading foreign key constraints.';
    }

    public function up(Schema $schema): void
    {
        $documentation = $schema->getTable('documentation');
        $documentationImages = $schema->getTable('documentation_images');
        $shareLinks = $schema->getTable('share_links');

        // ── Indexes for hot lookup paths ──────────────────────────────
        if (!$documentation->hasIndex('idx_documentation_schedule_session')) {
            $documentation->addIndex(
                ['schedule_id', 'timeslot', 'room'],
                'idx_documentation_schedule_session'
            );
        }
        if (!$documentation->hasIndex('idx_documentation_user_id')) {
            $documentation->addIndex(['user_id'], 'idx_documentation_user_id');
        }
        if (!$documentation->hasIndex('idx_documentation_guest_id')) {
            $documentation->addIndex(['guest_id'], 'idx_documentation_guest_id');
        }
        if (!$documentationImages->hasIndex('idx_doc_images_documentation_id')) {
            $documentationImages->addIndex(
                ['documentation_id'],
                'idx_doc_images_documentation_id'
            );
        }
        if (!$shareLinks->hasIndex('idx_share_links_schedule_id')) {
            $shareLinks->addIndex(['schedule_id'], 'idx_share_links_schedule_id');
        }
        if (!$shareLinks->hasIndex('idx_share_links_created_by')) {
            $shareLinks->addIndex(['created_by'], 'idx_share_links_created_by');
        }

        // ── Foreign keys with ON DELETE CASCADE ───────────────────────
        // Guard against duplicate constraint names across platforms.
        $existingDocFks = array_map(
            static fn(ForeignKeyConstraint $fk): string => (string)$fk->getObjectName(),
            $documentation->getForeignKeys()
        );
        if (!in_array('fk_documentation_schedule_id', $existingDocFks, true)) {
            $documentation->addForeignKeyConstraint(
                'schedules',
                ['schedule_id'],
                ['hashed_id'],
                ['onDelete' => 'CASCADE', 'name' => 'fk_documentation_schedule_id']
            );
        }

        $existingImageFks = array_map(
            static fn(ForeignKeyConstraint $fk): string => (string)$fk->getObjectName(),
            $documentationImages->getForeignKeys()
        );
        if (!in_array('fk_doc_images_documentation_id', $existingImageFks, true)) {
            $documentationImages->addForeignKeyConstraint(
                'documentation',
                ['documentation_id'],
                ['id'],
                ['onDelete' => 'CASCADE', 'name' => 'fk_doc_images_documentation_id']
            );
        }

        $existingShareFks = array_map(
            static fn(ForeignKeyConstraint $fk): string => (string)$fk->getObjectName(),
            $shareLinks->getForeignKeys()
        );
        if (!in_array('fk_share_links_schedule_id', $existingShareFks, true)) {
            $shareLinks->addForeignKeyConstraint(
                'schedules',
                ['schedule_id'],
                ['hashed_id'],
                ['onDelete' => 'CASCADE', 'name' => 'fk_share_links_schedule_id']
            );
        }
    }

    public function down(Schema $schema): void
    {
        $documentation = $schema->getTable('documentation');
        $documentationImages = $schema->getTable('documentation_images');
        $shareLinks = $schema->getTable('share_links');

        foreach ($documentation->getForeignKeys() as $fk) {
            $documentation->dropForeignKey((string)$fk->getObjectName());
        }
        foreach ($documentationImages->getForeignKeys() as $fk) {
            $documentationImages->dropForeignKey((string)$fk->getObjectName());
        }
        foreach ($shareLinks->getForeignKeys() as $fk) {
            $shareLinks->dropForeignKey((string)$fk->getObjectName());
        }

        $docIndexNames = [
            'idx_documentation_schedule_session',
            'idx_documentation_user_id',
            'idx_documentation_guest_id',
        ];
        foreach ($docIndexNames as $name) {
            if ($documentation->hasIndex($name)) {
                $documentation->dropIndex($name);
            }
        }
        if ($documentationImages->hasIndex('idx_doc_images_documentation_id')) {
            $documentationImages->dropIndex('idx_doc_images_documentation_id');
        }
        $shareIndexNames = ['idx_share_links_schedule_id', 'idx_share_links_created_by'];
        foreach ($shareIndexNames as $name) {
            if ($shareLinks->hasIndex($name)) {
                $shareLinks->dropIndex($name);
            }
        }
    }
}
