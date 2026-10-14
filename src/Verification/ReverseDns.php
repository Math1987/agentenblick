<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class ReverseDns
{
    public function __construct(private readonly Connection $connection, private readonly SystemConfigService $config)
    {
    }

    /** @param list<string> $suffixes */
    public function verify(?string $ip, string $ipHmac, string $operator, array $suffixes): bool
    {
        if ($ip === null || $suffixes === []) {
            return false;
        }
        $name = 'rdns:' . $operator . ':' . $ipHmac;
        $cached = $this->connection->fetchAssociative('SELECT value, updated_at FROM agentenblick_state WHERE name = ?', [$name]);
        if ($cached !== false && time() - (new \DateTimeImmutable($cached['updated_at'], new \DateTimeZone('UTC')))->getTimestamp() < 86400) {
            return $cached['value'] === '1';
        }
        if ($this->config->getBool('AithosAgentenblick.config.offline')) {
            return false;
        }
        $binary = (new PhpExecutableFinder())->find(false);
        if ($binary === false) {
            return false;
        }
        $verified = false;
        try {
            $process = new Process([$binary, dirname(__DIR__) . '/Resources/rdns-worker.php']);
            $process->setInput(json_encode(['ip' => $ip, 'suffixes' => $suffixes], JSON_THROW_ON_ERROR));
            $process->setTimeout(1.0);
            $process->run();
            $verified = $process->isSuccessful() && $process->getOutput() === '1';
        } catch (\Throwable) {
            $verified = false;
        }
        $this->connection->executeStatement('INSERT INTO agentenblick_state (name, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$name, $verified ? '1' : '0', gmdate('Y-m-d H:i:s')]);
        return $verified;
    }
}
