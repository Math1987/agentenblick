<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1791936002BrowserFamily extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791936002;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('agentenblick_daily');
        if (!isset($columns['browser_family'])) {
            // The empty value is reserved for non-human and historical counters.
            // No browser family is added to the human session rows.
            $connection->executeStatement("ALTER TABLE agentenblick_daily
                ADD browser_family VARCHAR(24) NOT NULL DEFAULT '',
                DROP PRIMARY KEY,
                ADD PRIMARY KEY (day, sales_channel_id, operator, agent, kind, evidence, browser_family)");
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
