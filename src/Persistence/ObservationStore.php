<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Persistence;

use Aithos\Agentenblick\Domain\Kind;
use Doctrine\DBAL\Connection;

final class ObservationStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array<string, mixed> $row */
    public function record(array $row): void
    {
        $lock = 'agentenblick:' . substr($row['session_key'], 0, 48);
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            throw new \RuntimeException('session_lock_busy');
        }
        try {
            $this->connection->transactional(function (Connection $connection) use ($row): void {
                $cutoff = (new \DateTimeImmutable($row['observed_at'], new \DateTimeZone('UTC')))->modify('-30 minutes')->format('Y-m-d H:i:s.v');
                $session = $connection->fetchOne(
                    'SELECT id FROM agentenblick_session WHERE session_key = ? AND sales_channel_id = ? AND agent = ? AND last_seen >= ? ORDER BY last_seen DESC LIMIT 1 FOR UPDATE',
                    [$row['session_key'], $row['sales_channel_id'], $row['agent'], $cutoff],
                );
                $new = $session === false;
                if ($new) {
                    $session = random_bytes(16);
                    $connection->insert('agentenblick_session', [
                        'id' => $session, 'session_key' => $row['session_key'], 'key_day' => $row['day'],
                        'sales_channel_id' => $row['sales_channel_id'], 'first_seen' => $row['observed_at'],
                        'last_seen' => $row['observed_at'], 'operator' => $row['operator'], 'agent' => $row['agent'],
                        'kind' => $row['kind'], 'evidence' => $row['evidence'], 'linkage' => $row['linkage'], 'requests' => 1,
                    ]);
                } else {
                    $connection->executeStatement('UPDATE agentenblick_session SET last_seen = ?, requests = requests + 1 WHERE id = ?', [$row['observed_at'], $session]);
                }
                if ($row['kind'] !== Kind::HumanOrUnknown->value) {
                    $request = $row;
                    unset($request['day'], $request['session_key'], $request['linkage']);
                    $request['id'] = random_bytes(16);
                    $request['session_id'] = $session;
                    $connection->insert('agentenblick_request', $request);
                }
                $connection->executeStatement(
                    'INSERT INTO agentenblick_daily (day, sales_channel_id, operator, agent, kind, evidence, requests, sessions) VALUES (?, ?, ?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE requests = requests + 1, sessions = sessions + VALUES(sessions)',
                    [$row['day'], $row['sales_channel_id'], $row['operator'], $row['agent'], $row['kind'], $row['evidence'], $new ? 1 : 0],
                );
            });
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
