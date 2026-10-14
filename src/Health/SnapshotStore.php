<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Health;

use Doctrine\DBAL\Connection;

final class SnapshotStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array{id: string, salesChannelId: string, url: string} $domain
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $policy
     */
    public function save(array $domain, array $snapshot, array $policy): void
    {
        $this->connection->executeStatement('INSERT INTO agentenblick_health_snapshot (domain_id, sales_channel_id, snapshot_json, policy_json, checked_at) VALUES (UNHEX(?), ?, ?, ?, ?) ON DUPLICATE KEY UPDATE sales_channel_id = VALUES(sales_channel_id), snapshot_json = VALUES(snapshot_json), policy_json = VALUES(policy_json), checked_at = VALUES(checked_at)', [$domain['id'], $domain['salesChannelId'], json_encode($snapshot, JSON_THROW_ON_ERROR), json_encode($policy, JSON_THROW_ON_ERROR), (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v')]);
    }

    /** @return array{snapshot: array<string, mixed>, policy: array<string, mixed>, checkedAt: string}|null */
    public function load(string $domainId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT snapshot_json, policy_json, checked_at FROM agentenblick_health_snapshot WHERE domain_id = UNHEX(?)', [$domainId]);
        if ($row === false) {
            return null;
        }
        $snapshot = json_decode((string) $row['snapshot_json'], true, 24, JSON_THROW_ON_ERROR);
        $policy = json_decode((string) $row['policy_json'], true, 24, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || !is_array($policy)) {
            return null;
        }
        return ['snapshot' => $snapshot, 'policy' => $policy, 'checkedAt' => (string) $row['checked_at']];
    }
}
