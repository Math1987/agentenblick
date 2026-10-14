<?php

declare(strict_types=1);

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
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 2)', [$lock]) !== 1) {
            throw new \RuntimeException('session_lock_busy');
        }
        try {
            $this->connection->transactional(function (Connection $connection) use ($row): void {
                $observedAt = new \DateTimeImmutable($row['observed_at'], new \DateTimeZone('UTC'));
                $cutoff = $observedAt->modify('-30 minutes')->format('Y-m-d H:i:s.v');
                $upper = $observedAt->modify('+30 minutes')->format('Y-m-d H:i:s.v');
                $session = $connection->fetchAssociative(
                    'SELECT * FROM agentenblick_session WHERE session_key = ? AND sales_channel_id = ? AND agent = ? AND last_seen >= ? AND first_seen <= ? ORDER BY last_seen DESC LIMIT 1 FOR UPDATE',
                    [$row['session_key'], $row['sales_channel_id'], $row['agent'], $cutoff, $upper],
                );
                $new = $session === false;
                if ($new) {
                    $sessionId = random_bytes(16);
                    $connection->insert('agentenblick_session', [
                        'id' => $sessionId, 'session_key' => $row['session_key'], 'key_day' => $row['day'],
                        'sales_channel_id' => $row['sales_channel_id'], 'first_seen' => $row['observed_at'],
                        'last_seen' => $row['observed_at'], 'operator' => $row['operator'], 'agent' => $row['agent'],
                        'kind' => $row['kind'], 'evidence' => $row['evidence'], 'linkage' => $row['linkage'], 'requests' => 1,
                    ]);
                } else {
                    $sessionId = $session['id'];
                    $ranks = ['none' => 0, 'declared_unverified' => 1, 'verified_rdns' => 2, 'verified_ip' => 3, 'verified_signature' => 4];
                    $evidence = ($ranks[$row['evidence']] ?? 0) > ($ranks[$session['evidence']] ?? 0) ? $row['evidence'] : $session['evidence'];
                    $firstSeen = min($session['first_seen'], $row['observed_at']);
                    $connection->executeStatement('UPDATE agentenblick_session SET first_seen = LEAST(first_seen, ?), last_seen = GREATEST(last_seen, ?), requests = requests + 1, evidence = ? WHERE id = ?', [$row['observed_at'], $row['observed_at'], $evidence, $sessionId]);
                    // Sessions use their strongest synchronous proof, while each request
                    // keeps its own evidence. A session is moved, never counted again.
                    $from = $session + ['day' => substr($session['first_seen'], 0, 10)];
                    if ($row['kind'] !== Kind::HumanOrUnknown->value) {
                        AggregateCorrection::move($connection, 'sessions', $from, array_replace($from, ['day' => substr($firstSeen, 0, 10), 'evidence' => $evidence]));
                    }
                }
                if ($row['kind'] !== Kind::HumanOrUnknown->value) {
                    $request = $row;
                    unset($request['day'], $request['session_key'], $request['linkage'], $request['browser_family']);
                    $request['id'] = random_bytes(16);
                    $request['session_id'] = $sessionId;
                    $connection->insert('agentenblick_request', $request);
                }
                $connection->executeStatement(
                    'INSERT INTO agentenblick_daily (day, sales_channel_id, operator, agent, kind, evidence, browser_family, requests, sessions) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE requests = requests + 1, sessions = sessions + VALUES(sessions)',
                    [$row['day'], $row['sales_channel_id'], $row['operator'], $row['agent'], $row['kind'], $row['evidence'], $row['browser_family'] ?? '', $new ? 1 : 0],
                );
            });
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
