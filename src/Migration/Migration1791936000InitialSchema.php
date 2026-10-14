<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1791936000InitialSchema extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791936000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agentenblick_request (
            id BINARY(16) NOT NULL PRIMARY KEY,
            session_id BINARY(16) NOT NULL,
            observed_at DATETIME(3) NOT NULL,
            sales_channel_id VARCHAR(32) NOT NULL,
            route VARCHAR(190) NOT NULL,
            method VARCHAR(10) NOT NULL,
            status SMALLINT UNSIGNED NOT NULL,
            operator VARCHAR(64) NOT NULL,
            agent VARCHAR(100) NOT NULL,
            kind VARCHAR(32) NOT NULL,
            evidence VARCHAR(32) NOT NULL,
            reason VARCHAR(64) NOT NULL,
            ip_hmac CHAR(64) NOT NULL,
            user_agent VARCHAR(1024) NOT NULL,
            network_prefix VARCHAR(50) DEFAULT NULL,
            signature_input TEXT DEFAULT NULL,
            signature_value TEXT DEFAULT NULL,
            signature_agent VARCHAR(2048) DEFAULT NULL,
            key_id VARCHAR(128) DEFAULT NULL,
            INDEX idx_agentenblick_request_time (observed_at),
            INDEX idx_agentenblick_request_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agentenblick_session (
            id BINARY(16) NOT NULL PRIMARY KEY,
            session_key CHAR(64) NOT NULL,
            key_day DATE NOT NULL,
            sales_channel_id VARCHAR(32) NOT NULL,
            first_seen DATETIME(3) NOT NULL,
            last_seen DATETIME(3) NOT NULL,
            operator VARCHAR(64) NOT NULL,
            agent VARCHAR(100) NOT NULL,
            kind VARCHAR(32) NOT NULL,
            evidence VARCHAR(32) NOT NULL,
            linkage VARCHAR(16) NOT NULL,
            requests BIGINT UNSIGNED NOT NULL DEFAULT 1,
            INDEX idx_agentenblick_session_lookup (session_key, sales_channel_id, last_seen),
            INDEX idx_agentenblick_session_expiry (last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agentenblick_daily (
            day DATE NOT NULL,
            sales_channel_id VARCHAR(32) NOT NULL,
            operator VARCHAR(64) NOT NULL,
            agent VARCHAR(100) NOT NULL,
            kind VARCHAR(32) NOT NULL,
            evidence VARCHAR(32) NOT NULL,
            requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sessions BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (day, sales_channel_id, operator, agent, kind, evidence)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agentenblick_ip_ranges (
            agent VARCHAR(100) NOT NULL PRIMARY KEY,
            ranges_json MEDIUMTEXT NOT NULL,
            pending_json MEDIUMTEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agentenblick_key_cache (
            origin VARCHAR(190) NOT NULL PRIMARY KEY,
            document_json TEXT NOT NULL,
            fetched_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agentenblick_state (
            name VARCHAR(190) NOT NULL PRIMARY KEY,
            value MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
