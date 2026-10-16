<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\WebMcp;

use Shopware\Core\System\SystemConfig\SystemConfigService;

final class Settings
{
    public function __construct(private readonly SystemConfigService $config)
    {
    }

    public function enabled(string $salesChannelId): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $salesChannelId) === 1
            && $this->config->getBool('AithosAgentenblick.config.webMcpEnabled', $salesChannelId);
    }
}
