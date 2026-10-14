<?php

declare(strict_types=1);

namespace Aithos\Agentenblick;

use Aithos\Agentenblick\Benchmark\Lifecycle;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

final class AithosAgentenblick extends Plugin
{
    public function executeComposerCommands(): bool
    {
        return true;
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        if ($this->container === null) {
            throw new \LogicException('Container unavailable during uninstall.');
        }
        $status = Lifecycle::manager($this->container)->disable();
        if ($status['pendingWithdrawal'] && !$uninstallContext->keepUserData()) {
            throw new \RuntimeException('Benchmark withdrawal is pending. Keep plugin data or restore connectivity and retry before deleting its authentication secret.');
        }
        if ($uninstallContext->keepUserData()) {
            return;
        }
        $connection = $this->container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new \LogicException('Database connection unavailable during uninstall.');
        }
        foreach (['request', 'session', 'daily', 'journey_daily', 'ip_ranges', 'key_cache', 'state', 'benchmark_draft'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS agentenblick_' . $table);
        }
        // Core only removes keys declared in config.xml; internal encrypted state
        // must also disappear when the user explicitly requests data deletion.
        $connection->executeStatement("DELETE FROM system_config WHERE configuration_key LIKE 'AithosAgentenblick.%'");
    }
}
