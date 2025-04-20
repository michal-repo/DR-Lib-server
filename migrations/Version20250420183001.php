<?php

declare(strict_types=1);

namespace migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250420183001 extends AbstractMigration {
    public function getDescription(): string {
        return '';
    }

    public function up(Schema $schema): void {
        $this->addSql('CREATE TABLE `favorites` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `file` varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
            `user_id` int(10) unsigned NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `favorites_unique` (`file`,`user_id`),
            KEY `idx_favorites_user_FK` (`user_id`),
            CONSTRAINT `idx_favorites_user_FK` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void {
        $this->addSql('DROP TABLE `favorites`');
    }
}
