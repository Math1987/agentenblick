<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1791936003SessionProofReview extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791936003;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('agentenblick_session');
        if (!isset($columns['proof_review_after'])) {
            $connection->executeStatement('ALTER TABLE agentenblick_session ADD proof_review_after DATETIME DEFAULT NULL, ADD INDEX idx_agentenblick_proof_review (proof_review_after)');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
