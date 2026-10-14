<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;

final class DailyKey
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function current(string $day): string
    {
        // Late termination must neither recreate yesterday's key nor replace today's
        // key with an older one. Pseudonymisation happens on the current UTC day.
        $day = max($day, gmdate('Y-m-d'));
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
        // Read on every request: an inter-request cache could reuse a superseded
        // key. Only creation/rotation needs to serialize writers on this row.
        // An outer repeatable-read transaction may hold an old snapshot; retain
        // the locking read in that case, which reads the latest committed row.
        if (!$this->connection->isTransactionActive()) {
            $current = $this->connection->fetchAssociative("SELECT value, updated_at FROM agentenblick_state WHERE name = 'daily_hmac_key'");
            if ($current !== false && substr($current['updated_at'], 0, 10) >= $day) {
                return self::decode($current['value']);
            }
        }
        return $this->connection->transactional(function (Connection $connection) use ($day): string {
            $connection->executeStatement(
                "INSERT IGNORE INTO agentenblick_state (name, value, updated_at) VALUES ('daily_hmac_key', ?, ?)",
                [base64_encode(random_bytes(32)), $day . ' 00:00:00'],
            );
            $row = $connection->fetchAssociative("SELECT value, updated_at FROM agentenblick_state WHERE name = 'daily_hmac_key' FOR UPDATE");
            if ($row === false) {
                throw new \RuntimeException('hmac_key_unavailable');
            }
            if (substr($row['updated_at'], 0, 10) < $day) {
                $row['value'] = base64_encode(random_bytes(32));
                $connection->executeStatement("UPDATE agentenblick_state SET value = ?, updated_at = ? WHERE name = 'daily_hmac_key'", [$row['value'], $day . ' 00:00:00']);
            }
            return self::decode($row['value']);
        });
    }

    private static function decode(string $encoded): string
    {
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('invalid_hmac_key');
        }
        return $key;
    }
}
