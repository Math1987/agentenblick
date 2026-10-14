<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Journey;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;

final class SessionCloser
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{closed: int, busy: int, remaining: int} */
    public function run(?int $now = null): array
    {
        $now ??= time();
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
        $cutoff = gmdate('Y-m-d H:i:s', $now - 1800);
        $rows = $this->connection->fetchAllAssociative("SELECT id, session_key FROM agentenblick_session WHERE kind <> 'human_or_unknown' AND closed_at IS NULL AND last_seen < ? ORDER BY last_seen LIMIT 200", [$cutoff]);
        $result = ['closed' => 0, 'busy' => 0, 'remaining' => 0];
        $started = microtime(true);
        foreach ($rows as $candidate) {
            if (microtime(true) - $started > 10) {
                break;
            }
            $lock = 'agentenblick:' . substr((string) $candidate['session_key'], 0, 48);
            if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
                ++$result['busy'];
                continue;
            }
            try {
                $result['closed'] += $this->connection->transactional(function (Connection $connection) use ($candidate, $cutoff, $now): int {
                    $session = $connection->fetchAssociative('SELECT * FROM agentenblick_session WHERE id = ? FOR UPDATE', [$candidate['id']]);
                    if ($session === false || $session['closed_at'] !== null || $session['last_seen'] >= $cutoff) {
                        return 0;
                    }
                    $session['closed_at'] = gmdate('Y-m-d H:i:s', $now);
                    $connection->update('agentenblick_session', ['closed_at' => $session['closed_at']], ['id' => $session['id']]);
                    JourneyContribution::reconcile($connection, $session);
                    return 1;
                });
            } finally {
                $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
        $result['remaining'] = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM agentenblick_session WHERE kind <> 'human_or_unknown' AND closed_at IS NULL AND last_seen < ?", [$cutoff]);
        return $result;
    }
}
