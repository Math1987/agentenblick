<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Benchmark;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/** @phpstan-import-type Settings from State */
final class Drafts
{
    public function __construct(private readonly Connection $connection, private readonly State $settings, private readonly string $shopwareVersion)
    {
    }

    /** @param Settings $state
     * @return list<array<string, mixed>>
     */
    public function prepare(array $state, string $period): array
    {
        [$start, $end] = Period::bounds($period);
        $existing = $this->rows($state['installationId'], $period);
        if ($existing !== []) {
            return $existing;
        }
        $rows = $this->connection->fetchAllAssociative('SELECT sales_channel_id, kind, evidence, operator, SUM(sessions) AS sessions FROM agentenblick_daily WHERE day >= ? AND day < ? GROUP BY sales_channel_id, kind, evidence, operator ORDER BY sales_channel_id, kind, evidence, operator LIMIT 20001', [$start, $end]);
        if (count($rows) > 20000) {
            throw new \RuntimeException('benchmark_scope_too_large');
        }
        $groups = [];
        $total = 0;
        foreach ($rows as $row) {
            $groups[(string) $row['sales_channel_id']][] = $row;
            $total += max(0, (int) $row['sessions']);
        }
        if (count($groups) > 32) {
            throw new \RuntimeException('benchmark_too_many_channels');
        }
        if ($groups === []) {
            return [];
        }
        $country = $this->connection->fetchOne('SELECT c.iso FROM sales_channel s INNER JOIN country c ON c.id = s.country_id WHERE s.active = 1 AND s.type_id = UNHEX(?) ORDER BY s.created_at, s.id LIMIT 1', [Defaults::SALES_CHANNEL_TYPE_STOREFRONT]);
        if (!is_string($country) || !preg_match('/^[A-Z]{2}$/D', $country)) {
            throw new \RuntimeException('benchmark_country_unavailable');
        }
        if (!preg_match('/^(6\.[0-9]+)(?:\.|$)/D', $this->shopwareVersion, $version)) {
            throw new \RuntimeException('benchmark_shopware_version_unavailable');
        }
        $segment = [
            'country' => $country, 'shopware' => $version[1], 'plugin' => '0.1.0', 'trafficBucket' => Payload::bucket($total),
            'agenticCommerceExtension' => (bool) $this->connection->fetchOne("SELECT 1 FROM plugin WHERE name = 'SwagAgenticCommerce' AND active = 1 LIMIT 1"),
        ];
        foreach (array_keys($groups) as $channel) {
            $state['channels'][$channel] ??= Payload::uuid();
        }
        $this->settings->save($state);
        $this->connection->transactional(function () use ($groups, $state, $period, $segment): void {
            foreach ($groups as $channel => $group) {
                $bytes = Payload::bytes(Payload::document($state['installationId'], $state['channels'][$channel], $period, $state['demo'], $segment, $group));
                $this->connection->insert('agentenblick_benchmark_draft', [
                    'installation_id' => $state['installationId'], 'channel_id' => $state['channels'][$channel], 'period' => $period,
                    'payload' => $bytes, 'sha256' => hash('sha256', $bytes), 'status' => 'draft', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
        });
        return $this->rows($state['installationId'], $period);
    }

    /** @return list<array<string, mixed>> */
    public function rows(?string $installation, ?string $period = null): array
    {
        $where = $installation === null ? '1 = 1' : 'installation_id = ?';
        $parameters = $installation === null ? [] : [$installation];
        if ($period !== null) {
            $where .= ' AND period = ?';
            $parameters[] = $period;
        }
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM agentenblick_benchmark_draft WHERE ' . $where . ' ORDER BY period DESC, channel_id LIMIT 6000', $parameters);
        return array_map(self::publicRow(...), $rows);
    }

    /** @return ?array<string, mixed> */
    public function next(string $installation): ?array
    {
        $this->expire();
        $row = $this->connection->fetchAssociative("SELECT * FROM agentenblick_benchmark_draft WHERE installation_id = ? AND status IN ('draft','pending','failed') ORDER BY attempts, updated_at, period, channel_id LIMIT 1", [$installation]);
        return $row === false ? null : self::publicRow($row);
    }

    /** @param array<string, mixed> $row */
    public function result(string $installation, array $row, string $status, string $code): void
    {
        $this->connection->executeStatement('UPDATE agentenblick_benchmark_draft SET status = ?, result_code = ?, updated_at = ?, attempts = attempts + ?, sent_at = COALESCE(?, sent_at) WHERE installation_id = ? AND channel_id = ? AND period = ?', [$status, $code, gmdate('Y-m-d H:i:s'), $status === 'pending' ? 1 : 0, $status === 'sent' ? gmdate('Y-m-d H:i:s') : null, $installation, $row['channelId'], $row['period']]);
    }

    public function purge(): int
    {
        $this->expire();
        return (int) $this->connection->executeStatement('DELETE FROM agentenblick_benchmark_draft WHERE created_at < ?', [(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-13 months')->format('Y-m-d H:i:s')]);
    }

    public function withdrawn(string $installation): void
    {
        $this->connection->executeStatement("UPDATE agentenblick_benchmark_draft SET status = 'withdrawn', result_code = 'withdrawn', updated_at = ? WHERE installation_id = ?", [gmdate('Y-m-d H:i:s'), $installation]);
    }

    private function expire(): void
    {
        $minimum = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-13 weeks')->format('o-\WW');
        $this->connection->executeStatement("UPDATE agentenblick_benchmark_draft SET status = 'expired', result_code = 'period_expired', updated_at = ? WHERE status IN ('draft','pending','failed') AND period < ?", [gmdate('Y-m-d H:i:s'), $minimum]);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function publicRow(array $row): array
    {
        return ['channelId' => $row['channel_id'], 'period' => $row['period'], 'json' => $row['payload'], 'payload' => json_decode((string) $row['payload'], true, 32, JSON_THROW_ON_ERROR), 'sha256' => $row['sha256'], 'status' => $row['status'], 'resultCode' => $row['result_code'], 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'], 'sentAt' => $row['sent_at']];
    }
}
