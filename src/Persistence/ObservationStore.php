<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Persistence;

use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Journey\JourneyContribution;
use Aithos\Agentenblick\Journey\Stage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;

final class ObservationStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array<string, mixed> $row */
    public function record(array $row): void
    {
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
        $human = $row['kind'] === Kind::HumanOrUnknown->value;
        $stage = !$human && is_string($row['stage'] ?? null) ? Stage::tryFrom($row['stage']) : null;
        $row['stage'] = $stage->value ?? '';
        $row['failure_mask'] = $human ? 0 : ((int) ($row['failure_mask'] ?? 0) & 4095);
        $row['known_mask'] = $human ? 0 : ((int) ($row['known_mask'] ?? 0) & 127);
        $row['order_id'] = !$human && is_string($row['order_id'] ?? null) && strlen($row['order_id']) === 16 ? $row['order_id'] : null;
        $lock = 'agentenblick:' . substr($row['session_key'], 0, 48);
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 2)', [$lock]) !== 1) {
            throw new \RuntimeException('session_lock_busy');
        }
        try {
            $this->connection->transactional(function (Connection $connection) use ($row, $stage): void {
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
                        'steps_mask' => $stage?->bit() ?? 0, 'failures_mask' => $row['failure_mask'], 'known_mask' => $row['known_mask'],
                        'last_stage' => $row['stage'], 'last_stage_at' => $stage === null ? null : $row['observed_at'],
                    ]);
                } else {
                    $sessionId = $session['id'];
                    $ranks = ['none' => 0, 'declared_unverified' => 1, 'verified_rdns' => 2, 'verified_ip' => 3, 'verified_signature' => 4];
                    $evidence = ($ranks[$row['evidence']] ?? 0) > ($ranks[$session['evidence']] ?? 0) ? $row['evidence'] : $session['evidence'];
                    $firstSeen = min($session['first_seen'], $row['observed_at']);
                    $connection->executeStatement('UPDATE agentenblick_session SET first_seen = LEAST(first_seen, ?), last_seen = GREATEST(last_seen, ?), requests = requests + 1, evidence = ? WHERE id = ?', [$row['observed_at'], $row['observed_at'], $evidence, $sessionId]);
                    $changes = [
                        'steps_mask' => (int) $session['steps_mask'] | ($stage?->bit() ?? 0),
                        'failures_mask' => (int) $session['failures_mask'] | $row['failure_mask'],
                        'known_mask' => (int) $session['known_mask'] | $row['known_mask'],
                    ];
                    if ($stage !== null && ($session['last_stage_at'] === null || $row['observed_at'] >= $session['last_stage_at'])) {
                        $changes += ['last_stage' => $stage->value, 'last_stage_at' => $row['observed_at']];
                    }
                    // A recently received late observation may make a closed session
                    // live again. Its old aggregate contribution is removed below.
                    if ($session['closed_at'] !== null && max($session['last_seen'], $row['observed_at']) >= gmdate('Y-m-d H:i:s', time() - 1800)) {
                        $changes['closed_at'] = null;
                    }
                    $connection->update('agentenblick_session', $changes, ['id' => $sessionId]);
                    // Sessions use their strongest synchronous proof, while each request
                    // keeps its own evidence. A session is moved, never counted again.
                    $from = $session + ['day' => substr($session['first_seen'], 0, 10)];
                    if ($row['kind'] !== Kind::HumanOrUnknown->value) {
                        AggregateCorrection::move($connection, 'sessions', $from, array_replace($from, ['day' => substr($firstSeen, 0, 10), 'evidence' => $evidence]));
                    }
                    $updated = $connection->fetchAssociative('SELECT * FROM agentenblick_session WHERE id = ?', [$sessionId]);
                    if ($updated !== false) {
                        JourneyContribution::reconcile($connection, $updated);
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
