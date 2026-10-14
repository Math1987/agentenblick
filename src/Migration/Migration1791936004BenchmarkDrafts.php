<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1791936004BenchmarkDrafts extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791936004;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agentenblick_benchmark_draft (
            installation_id CHAR(36) NOT NULL,
            channel_id CHAR(36) NOT NULL,
            period CHAR(8) NOT NULL,
            payload TEXT NOT NULL,
            sha256 CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'draft\',
            result_code VARCHAR(40) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (installation_id, channel_id, period),
            INDEX idx_agentenblick_benchmark_history (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
