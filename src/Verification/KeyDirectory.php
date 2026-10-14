<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class KeyDirectory
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
        private readonly string $environment,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(string $origin, Catalogue $catalogue): array
    {
        $agent = $catalogue->origin($origin);
        if ($agent === null || !is_string($agent['directory'] ?? null)) {
            throw new \UnexpectedValueException('origin_not_catalogued');
        }
        $cached = $this->connection->fetchAssociative('SELECT document_json, fetched_at FROM agentenblick_key_cache WHERE origin = ?', [$origin]);
        $age = $cached === false ? PHP_INT_MAX : time() - (new \DateTimeImmutable($cached['fetched_at'], new \DateTimeZone('UTC')))->getTimestamp();
        if ($cached !== false && $age <= 3600) {
            return json_decode($cached['document_json'], true, 32, JSON_THROW_ON_ERROR);
        }
        if ($this->config->getBool('AithosAgentenblick.config.offline')) {
            if ($cached !== false && $age <= 86400) {
                return json_decode($cached['document_json'], true, 32, JSON_THROW_ON_ERROR);
            }
            if (isset($agent['keys']) && is_array($agent['keys'])) {
                return ['keys' => $agent['keys']];
            }
            throw new \RuntimeException('offline_directory_unavailable');
        }
        try {
            $document = (new SafeFetcher())->json($agent['directory'], 65536, $this->environment === 'test');
            if (!isset($document['keys']) || !is_array($document['keys']) || count($document['keys']) > 100) {
                throw new \UnexpectedValueException('invalid_directory');
            }
            $this->connection->executeStatement('INSERT INTO agentenblick_key_cache (origin, document_json, fetched_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE document_json = VALUES(document_json), fetched_at = VALUES(fetched_at)', [$origin, json_encode($document, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s')]);
            return $document;
        } catch (\Throwable $error) {
            if ($cached !== false && $age <= 86400) {
                return json_decode($cached['document_json'], true, 32, JSON_THROW_ON_ERROR);
            }
            throw $error;
        }
    }
}
