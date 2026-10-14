<?php

declare(strict_types=1);

namespace Aithos\Agentenblick;

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
        if ($uninstallContext->keepUserData()) {
            return;
        }
        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new \LogicException('Database connection unavailable during uninstall.');
        }
        foreach (['request', 'session', 'daily', 'ip_ranges', 'key_cache', 'state'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS agentenblick_' . $table);
        }
    }
}
