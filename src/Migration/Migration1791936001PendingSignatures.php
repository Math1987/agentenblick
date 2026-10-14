<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1791936001PendingSignatures extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791936001;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('agentenblick_request');
        if (!isset($columns['pending_context'])) {
            $connection->executeStatement('ALTER TABLE agentenblick_request
                ADD pending_context TEXT DEFAULT NULL,
                ADD verify_after DATETIME DEFAULT NULL,
                ADD verify_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                ADD INDEX idx_agentenblick_pending (verify_after)');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
