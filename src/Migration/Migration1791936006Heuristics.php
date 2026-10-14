<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1791936006Heuristics extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791936006;
    }

    public function update(Connection $connection): void
    {
        foreach (['agentenblick_request', 'agentenblick_session'] as $table) {
            $columns = $connection->createSchemaManager()->listTableColumns($table);
            if ($table === 'agentenblick_session' && isset($columns['browser_family'])) {
                // Remove the provisional development column: browser families
                // belong only to aggregate counters, never temporary sessions.
                $connection->executeStatement('ALTER TABLE agentenblick_session DROP COLUMN browser_family');
            }
            $fields = ['heuristic_score' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0', 'heuristic_flags' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'heuristic_version' => "VARCHAR(16) NOT NULL DEFAULT ''", 'heuristic_threshold' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0', 'heuristic_epoch' => "VARCHAR(32) NOT NULL DEFAULT ''"];
            if ($table === 'agentenblick_session') {
                $fields += ['detected_at' => 'DATETIME(3) DEFAULT NULL', 'journey_partial' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0'];
            }
            foreach ($fields as $name => $definition) {
                if (!isset($columns[$name])) {
                    $connection->executeStatement('ALTER TABLE ' . $table . ' ADD ' . $name . ' ' . $definition);
                }
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
