<?php

declare(strict_types=1);

namespace migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250421165640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add thumbnail column to favorites table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `favorites` ADD `thumbnail` VARCHAR(600) COLLATE utf8mb4_unicode_ci NOT NULL AFTER `file`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `favorites` DROP COLUMN `thumbnail`');
    }
}
