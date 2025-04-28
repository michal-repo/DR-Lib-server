<?php

declare(strict_types=1);

namespace migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250428202356 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `reference_files` (
            `id` INT AUTO_INCREMENT NOT NULL,
            `name` VARCHAR(600) NOT NULL,
            `directory` VARCHAR(600) NOT NULL,
            `src` VARCHAR(600) NOT NULL,
            `thumbnail` VARCHAR(600) NOT NULL,
            `corrupted` tinyint NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(`id`),
            UNIQUE KEY `idx_src_unique` (`src`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `reference_files`');

    }
}
