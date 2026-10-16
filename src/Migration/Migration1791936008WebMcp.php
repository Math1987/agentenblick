<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1791936008WebMcp extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791936008;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('agentenblick_session');
        foreach ([
            'webmcp_scope' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'declared_service' => "VARCHAR(16) NOT NULL DEFAULT ''",
            'declared_intent' => "VARCHAR(32) NOT NULL DEFAULT ''",
            'declared_at' => 'DATETIME(3) DEFAULT NULL',
        ] as $name => $definition) {
            if (!isset($columns[$name])) {
                $connection->executeStatement('ALTER TABLE agentenblick_session ADD ' . $name . ' ' . $definition);
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
