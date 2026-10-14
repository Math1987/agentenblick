<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Benchmark;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class Lifecycle
{
    /** Shopware rebuilds the container without the plugin before uninstall(). */
    public static function manager(ContainerInterface $container): Manager
    {
        if ($container->has(Manager::class)) {
            $manager = $container->get(Manager::class);
            if ($manager instanceof Manager) {
                return $manager;
            }
        }
        $connection = $container->get(Connection::class);
        $config = $container->get(SystemConfigService::class);
        $secret = $container->getParameter('kernel.secret');
        $version = $container->getParameter('kernel.shopware_version');
        $environment = $container->getParameter('kernel.environment');
        if (!$connection instanceof Connection || !$config instanceof SystemConfigService || !is_string($secret) || !is_string($version) || !is_string($environment)) {
            throw new \RuntimeException('Core services are required to preserve benchmark withdrawal credentials during uninstall.');
        }
        $state = new State($connection, $config, new SecretVault($secret), $environment);
        return new Manager($connection, $state, new Drafts($connection, $state, $version), new Transport());
    }
}
