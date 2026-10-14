<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1791936005Journeys extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791936005;
    }

    public function update(Connection $connection): void
    {
        $definitions = [
            'agentenblick_request' => [
                'stage' => "VARCHAR(16) NOT NULL DEFAULT ''", 'failure_mask' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'known_mask' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'order_id' => 'BINARY(16) DEFAULT NULL',
            ],
            'agentenblick_session' => [
                'steps_mask' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'failures_mask' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'known_mask' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'last_stage' => "VARCHAR(16) NOT NULL DEFAULT ''",
                'last_stage_at' => 'DATETIME(3) DEFAULT NULL', 'closed_at' => 'DATETIME(3) DEFAULT NULL', 'contribution' => 'JSON DEFAULT NULL',
            ],
        ];
        foreach ($definitions as $table => $fields) {
            $columns = $connection->createSchemaManager()->listTableColumns($table);
            foreach ($fields as $name => $definition) {
                if (!isset($columns[$name])) {
                    $connection->executeStatement('ALTER TABLE ' . $table . ' ADD ' . $name . ' ' . $definition);
                }
            }
        }
        $indexes = $connection->createSchemaManager()->listTableIndexes('agentenblick_session');
        if (!isset($indexes['idx_agentenblick_closure'])) {
            $connection->executeStatement('CREATE INDEX idx_agentenblick_closure ON agentenblick_session (closed_at, last_seen)');
        }
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agentenblick_journey_daily (
            day DATE NOT NULL, sales_channel_id VARCHAR(32) NOT NULL, operator VARCHAR(64) NOT NULL,
            agent VARCHAR(100) NOT NULL, kind VARCHAR(32) NOT NULL, evidence VARCHAR(32) NOT NULL,
            metric VARCHAR(48) NOT NULL, sessions BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (day, sales_channel_id, operator, agent, kind, evidence, metric)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
