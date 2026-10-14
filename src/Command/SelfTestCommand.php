<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Command;

use Aithos\Agentenblick\Persistence\ProxyDiagnostics;
use Aithos\Agentenblick\Verification\CatalogueProvider;
use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agentenblick:selftest', description: 'Check dependencies, schema, catalogue, tasks and proxy diagnostics without network access')]
final class SelfTestCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogueProvider $catalogues,
        private readonly SystemConfigService $config,
        private readonly ProxyDiagnostics $diagnostics,
        private readonly bool $debug,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = ['php' => PHP_VERSION, 'sodium' => extension_loaded('sodium'), 'openssl' => extension_loaded('openssl'), 'debug' => $this->debug, 'offline' => $this->config->getBool('AithosAgentenblick.config.offline')];
        $ok = $report['sodium'] && $report['openssl'];
        try {
            $report['catalogue_agents'] = count($this->catalogues->load()->agents());
            foreach (['request', 'session', 'daily', 'ip_ranges', 'key_cache', 'state'] as $name) {
                $report['tables'][$name] = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM agentenblick_' . $name);
            }
            $report['tasks'] = $this->connection->fetchAllAssociative("SELECT name, status, run_interval FROM scheduled_task WHERE name LIKE 'agentenblick.%'");
            $report['proxy_diagnostics'] = $this->diagnostics->report();
            $report['official_extension'] = $this->connection->fetchAssociative("SELECT version, active FROM plugin WHERE name = 'SwagAgenticCommerce'") ?: null;
            $path = $this->projectDir . '/var/agentenblick/errors.json';
            $report['collection_errors'] = is_file($path) ? json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
        } catch (\Throwable $error) {
            $report['error_type'] = get_class($error);
            $ok = false;
        }
        $report['warning'] = $this->debug ? 'Debug mode can retain the client during kernel.terminate. Use production mode and verify web timing on your server.' : 'CLI cannot prove web response flushing; verify PHP-FPM and measure the actual HTTP server.';
        $report['ok'] = $ok;
        $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
