<?php

declare(strict_types=1);

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
    public function get(string $origin, Catalogue $catalogue, bool $cachedOnly = false): array
    {
        $agent = $catalogue->origin($origin);
        if ($agent === null) {
            throw new \UnexpectedValueException('origin_not_catalogued');
        }
        $cached = $this->connection->fetchAssociative('SELECT document_json, fetched_at FROM agentenblick_key_cache WHERE origin = ?', [$origin]);
        $age = $cached === false ? PHP_INT_MAX : time() - (new \DateTimeImmutable($cached['fetched_at'], new \DateTimeZone('UTC')))->getTimestamp();
        if ($cachedOnly) {
            $keys = is_array($agent['keys'] ?? null) ? $agent['keys'] : [];
            if ($cached !== false && $age >= 0 && $age <= 86400) {
                try {
                    $document = json_decode($cached['document_json'], true, 32, JSON_THROW_ON_ERROR);
                    if (is_array($document['keys'] ?? null)) {
                        $keys = array_merge($keys, $document['keys']);
                    }
                } catch (\JsonException) {
                    // The embedded public catalogue remains usable if a cache is broken.
                }
            }
            return ['keys' => $keys];
        }
        if ($cached !== false && $age >= 0 && $age <= 3600) {
            return json_decode($cached['document_json'], true, 32, JSON_THROW_ON_ERROR);
        }
        if ($this->config->getBool('AithosAgentenblick.config.offline')) {
            if ($cached !== false && $age >= 0 && $age <= 86400) {
                return json_decode($cached['document_json'], true, 32, JSON_THROW_ON_ERROR);
            }
            if (isset($agent['keys']) && is_array($agent['keys'])) {
                return ['keys' => $agent['keys']];
            }
            throw new \RuntimeException('offline_directory_unavailable');
        }
        try {
            if (!is_string($agent['directory'] ?? null)) {
                throw new \UnexpectedValueException('directory_not_catalogued');
            }
            $document = (new SafeFetcher())->json($agent['directory'], 65536, $this->environment === 'test');
            if (!isset($document['keys']) || !is_array($document['keys']) || count($document['keys']) > 100) {
                throw new \UnexpectedValueException('invalid_directory');
            }
            $this->connection->executeStatement('INSERT INTO agentenblick_key_cache (origin, document_json, fetched_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE document_json = VALUES(document_json), fetched_at = VALUES(fetched_at)', [$origin, json_encode($document, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s')]);
            return $document;
        } catch (\Throwable $error) {
            if ($cached !== false && $age >= 0 && $age <= 86400) {
                return json_decode($cached['document_json'], true, 32, JSON_THROW_ON_ERROR);
            }
            throw $error;
        }
    }

    /** Resolve an unambiguous public key from known material only, without network access. */
    public function knownOrigin(string $keyId, Catalogue $catalogue): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $keyId)) {
            return null;
        }
        $now = time();
        $cached = [];
        foreach ($this->connection->fetchAllAssociative('SELECT origin, document_json FROM agentenblick_key_cache WHERE fetched_at >= ? AND fetched_at <= ?', [gmdate('Y-m-d H:i:s', $now - 86400), gmdate('Y-m-d H:i:s', $now)]) as $row) {
            try {
                $document = json_decode($row['document_json'], true, 32, JSON_THROW_ON_ERROR);
                if (is_array($document['keys'] ?? null)) {
                    $cached[$row['origin']] = $document['keys'];
                }
            } catch (\JsonException) {
                // A broken cached document must not hide another usable public key.
            }
        }
        $origins = [];
        foreach ($catalogue->agents() as $agent) {
            $origin = $agent['origin'] ?? null;
            if (!is_string($origin)) {
                continue;
            }
            $keys = array_merge(is_array($agent['keys'] ?? null) ? $agent['keys'] : [], $cached[$origin] ?? []);
            foreach ($keys as $key) {
                try {
                    if (!is_array($key) || isset($key['d']) || ($key['kid'] ?? $keyId) !== $keyId) {
                        continue;
                    }
                    if (hash_equals(Jwk::thumbprint($key), $keyId)) {
                        $origins[$origin] = true;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        // A shared key without an origin cannot prove which catalogue identity sent it.
        return count($origins) === 1 ? array_key_first($origins) : null;
    }
}
