<?php

declare(strict_types=1);

namespace migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250420213922 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create jwt_tokens table to store user JWTs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `jwt_tokens` (
            `id` INT AUTO_INCREMENT NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `token` TEXT NOT NULL,
            `token_type` VARCHAR(50) NOT NULL DEFAULT "access",
            `user_agent` TEXT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` DATETIME NULL,
            INDEX `idx_jwt_user_id` (`user_id`),
            INDEX `idx_jwt_expires_at` (`expires_at`),
            INDEX `idx_jwt_user_token_type` (`user_id`, `token_type`),
            CONSTRAINT `fk_jwt_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            PRIMARY KEY(`id`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `jwt_tokens`');
    }
}
