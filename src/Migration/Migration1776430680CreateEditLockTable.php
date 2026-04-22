<?php
declare(strict_types=1);

namespace LaenenAdminLock\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1776430680CreateEditLockTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1776430680;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `lae_admin_lock` (
                `entity_name`  VARCHAR(32)  NOT NULL,
                `entity_id`    BINARY(16)   NOT NULL,
                `owner_token`  VARCHAR(96)  NOT NULL,
                `user_id`      BINARY(16)   NOT NULL,
                `user_label`   VARCHAR(255) NOT NULL DEFAULT '',
                `note`         VARCHAR(255) NULL,
                `locked_at`    DATETIME(3)  NOT NULL,
                `heartbeat_at` DATETIME(3)  NOT NULL,
                `expires_at`   DATETIME(3)  NOT NULL,
                PRIMARY KEY (`entity_name`, `entity_id`),
                KEY `idx_lae_admin_lock_expires_at` (`expires_at`),
                KEY `idx_lae_admin_lock_user_id`    (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
