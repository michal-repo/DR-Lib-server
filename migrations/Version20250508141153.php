<?php

declare(strict_types=1);

namespace migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250508141153 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refactor favorites table to use reference_files.id foreign key, replacing file and thumbnail columns.';
    }

    public function up(Schema $schema): void
    {
        // Step 1: Add the new reference_file_id column, initially nullable
        $this->addSql('ALTER TABLE `favorites` ADD `reference_file_id` INT NULL DEFAULT NULL AFTER `user_id`');

        // Step 2: Populate the new reference_file_id column
        // This assumes that every `favorites.file` has a corresponding `reference_files.src`.
        // If a favorite's file does not exist in reference_files, its reference_file_id will remain NULL.
        $this->addSql('UPDATE `favorites` f JOIN `reference_files` rf ON f.file = rf.src SET f.reference_file_id = rf.id');

        // At this point, you might want to add a check for `reference_file_id IS NULL` if you expect all rows to be matched.
        // For example: $this->warnIf($this->connection->fetchOne('SELECT COUNT(*) FROM favorites WHERE reference_file_id IS NULL') > 0, 'Warning: Some favorites entries could not be matched to reference_files.');
        // The migration proceeds assuming that if any reference_file_id is NULL, it's an issue to be resolved if the column must be NOT NULL.

        // Step 3: Drop the old unique constraint that uses the `file` column
        $this->addSql('ALTER TABLE `favorites` DROP INDEX `favorites_unique`');

        // Step 4: Drop the old `file` column
        $this->addSql('ALTER TABLE `favorites` DROP COLUMN `file`');

        // Step 5: Drop the old `thumbnail` column
        $this->addSql('ALTER TABLE `favorites` DROP COLUMN `thumbnail`');

        // Step 6: Modify reference_file_id to be NOT NULL
        // This step will fail if any `reference_file_id` is NULL after the update.
        // This enforces data integrity according to the new schema.
        $this->addSql('ALTER TABLE `favorites` MODIFY `reference_file_id` INT NOT NULL');

        // Step 7: Add the foreign key constraint
        $this->addSql('ALTER TABLE `favorites` ADD CONSTRAINT `FK_FAVORITES_REFERENCE_FILE` FOREIGN KEY (`reference_file_id`) REFERENCES `reference_files` (`id`) ON DELETE CASCADE');

        // Step 8: Add the new unique constraint on reference_file_id and user_id
        $this->addSql('ALTER TABLE `favorites` ADD UNIQUE KEY `favorites_unique` (`reference_file_id`, `user_id`)');
    }

    public function down(Schema $schema): void
    {
        // Step 1: Add back the `file` column, initially nullable
        $this->addSql('ALTER TABLE `favorites` ADD `file` VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `created_at`');

        // Step 2: Add back the `thumbnail` column, initially nullable
        $this->addSql('ALTER TABLE `favorites` ADD `thumbnail` VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `file`');

        // Step 3: Populate the `file` and `thumbnail` columns from `reference_files`
        $this->addSql('UPDATE `favorites` f JOIN `reference_files` rf ON f.reference_file_id = rf.id SET f.file = rf.src, f.thumbnail = rf.thumbnail');

        // Step 4: Drop the new unique constraint
        $this->addSql('ALTER TABLE `favorites` DROP INDEX `favorites_unique`');

        // Step 5: Drop the foreign key constraint
        $this->addSql('ALTER TABLE `favorites` DROP FOREIGN KEY `FK_FAVORITES_REFERENCE_FILE`');
        // InnoDB typically creates an index for the FK; dropping the FK constraint usually drops this index.
        // If you had an explicitly named index on `reference_file_id` separate from the FK, you'd drop it here.

        // Step 6: Drop the `reference_file_id` column
        $this->addSql('ALTER TABLE `favorites` DROP COLUMN `reference_file_id`');

        // Step 7: Modify `file` column to be NOT NULL (as it was originally)
        $this->addSql('ALTER TABLE `favorites` MODIFY `file` VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        
        // Step 8: Modify `thumbnail` column to be NOT NULL (as it was originally)
        $this->addSql('ALTER TABLE `favorites` MODIFY `thumbnail` VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');

        // Step 9: Add back the old unique constraint on `file` and `user_id`
        $this->addSql('ALTER TABLE `favorites` ADD UNIQUE KEY `favorites_unique` (`file`, `user_id`)');
    }
}