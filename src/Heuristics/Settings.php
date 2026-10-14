<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Heuristics;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Doctrine\DBAL\Connection;

final class Settings
{
    public const ENABLED = 'AithosAgentenblick.config.heuristicsEnabled';
    public const THRESHOLD = 'AithosAgentenblick.config.heuristicThreshold';
    public const EPOCH = 'AithosAgentenblick.config.heuristicEpoch';

    public function __construct(private readonly SystemConfigService $config, private readonly Connection $connection)
    {
    }

    /** @return array{enabled: bool, threshold: int, minThreshold: int, maxThreshold: int, experimental: bool, calibrated: bool, version: string, status: string} */
    public function read(): array
    {
        $context = $this->context();
        return ['enabled' => $context['enabled'], 'threshold' => $context['threshold'], 'minThreshold' => 30, 'maxThreshold' => 100, 'experimental' => true, 'calibrated' => false, 'version' => $context['version'], 'status' => $context['enabled'] ? 'experimental_not_calibrated' : 'disabled'];
    }

    /** @return array{enabled: bool, threshold: int, version: string, epoch: string} */
    public function context(): array
    {
        // getDomain reads current database values and applies managed Symfony
        // overrides; a long-running worker must not keep an old opt-in in cache.
        $values = $this->config->getDomain('AithosAgentenblick.config');
        $enabled = ($values[self::ENABLED] ?? false) === true;
        $threshold = $values[self::THRESHOLD] ?? 60;
        $threshold = is_int($threshold) ? max(30, min(100, $threshold)) : 60;
        $epoch = is_string($values[self::EPOCH] ?? null) ? $values[self::EPOCH] : 'default';
        return ['enabled' => $enabled, 'threshold' => $threshold, 'version' => HeuristicScore::VERSION, 'epoch' => substr(hash('sha256', HeuristicScore::VERSION . ':' . $threshold . ':' . $epoch), 0, 32)];
    }

    /** @return array<string, mixed> */
    public function save(bool $enabled, int $threshold): array
    {
        if ($threshold < 30 || $threshold > 100) {
            throw new \InvalidArgumentException('Heuristic threshold must be between 30 and 100.');
        }
        $current = $this->context();
        $values = [self::ENABLED => $enabled, self::THRESHOLD => $threshold];
        if ($current['enabled'] !== $enabled || $current['threshold'] !== $threshold) {
            $values[self::EPOCH] = bin2hex(random_bytes(16));
        }
        // Shopware setMultiple writes keys separately. Commit opt-in, threshold
        // and epoch together so a capture never sees an intermediate setting.
        $this->connection->transactional(function () use ($values): void {
            $this->config->setMultiple($values);
        });
        return $this->read();
    }
}
