<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1791936007Health extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791936007;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('agentenblick_request');
        if (!isset($columns['discovery_resource'])) {
            $connection->executeStatement("ALTER TABLE agentenblick_request ADD discovery_resource VARCHAR(16) NOT NULL DEFAULT '', ADD INDEX idx_ab_discovery (discovery_resource, observed_at)");
        }
        if (!isset($columns['discovery_completed_at'])) {
            $connection->executeStatement('ALTER TABLE agentenblick_request ADD discovery_completed_at DATETIME(3) NULL');
        }
        if (!isset($columns['domain_id'])) {
            $connection->executeStatement('ALTER TABLE agentenblick_request ADD domain_id BINARY(16) NULL, ADD INDEX idx_ab_domain_time (domain_id, observed_at)');
        }
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agentenblick_health_snapshot (domain_id BINARY(16) NOT NULL PRIMARY KEY, sales_channel_id VARCHAR(32) NOT NULL, snapshot_json MEDIUMTEXT NOT NULL, policy_json MEDIUMTEXT NOT NULL, checked_at DATETIME(3) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $connection->executeStatement("CREATE TABLE IF NOT EXISTS agentenblick_health_daily (day DATE NOT NULL, domain_id BINARY(16) NOT NULL, sales_channel_id VARCHAR(32) NOT NULL, agent VARCHAR(100) NOT NULL, evidence VARCHAR(32) NOT NULL, route VARCHAR(190) NOT NULL, status SMALLINT UNSIGNED NOT NULL, code VARCHAR(40) NOT NULL, rule_hash VARCHAR(32) NOT NULL DEFAULT '', rule_text TEXT NULL, reason_code VARCHAR(40) NOT NULL, snapshot_at DATETIME NULL, requests BIGINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (day, domain_id, agent, evidence, route, status, code, rule_hash), INDEX idx_ab_health_channel (sales_channel_id, day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
