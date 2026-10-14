<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Persistence;

use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Heuristics\Candidate;
use Aithos\Agentenblick\Heuristics\HeuristicScore;
use Aithos\Agentenblick\Heuristics\History;
use Aithos\Agentenblick\Journey\JourneyContribution;
use Aithos\Agentenblick\Journey\Stage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;

final class ObservationStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed> The committed minimized observation, never raw input.
     */
    public function record(array $row, ?Candidate $candidate = null): array
    {
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
        $lock = 'agentenblick:' . substr($row['session_key'], 0, 48);
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 2)', [$lock]) !== 1) {
            throw new \RuntimeException('session_lock_busy');
        }
        try {
            return $this->connection->transactional(function (Connection $connection) use ($row, $candidate): array {
                $observedAt = new \DateTimeImmutable($row['observed_at'], new \DateTimeZone('UTC'));
                $cutoff = $observedAt->modify('-30 minutes')->format('Y-m-d H:i:s.v');
                $upper = $observedAt->modify('+30 minutes')->format('Y-m-d H:i:s.v');
                $session = $connection->fetchAssociative(
                    'SELECT * FROM agentenblick_session WHERE session_key = ? AND sales_channel_id = ? AND agent = ? AND last_seen >= ? AND first_seen <= ? ORDER BY last_seen DESC LIMIT 1 FOR UPDATE',
                    [$row['session_key'], $row['sales_channel_id'], $row['agent'], $cutoff, $upper],
                );
                $new = $session === false;
                $sessionId = $new ? random_bytes(16) : $session['id'];
                if ($candidate !== null && $row['kind'] === Kind::HumanOrUnknown->value && $row['agent'] === 'unknown' && $row['operator'] === 'unknown'
                    && ($new || $session['kind'] === Kind::HumanOrUnknown->value || $session['evidence'] === 'suspected')) {
                    $row = $this->assess($connection, $row, $session, $sessionId, $candidate, $observedAt);
                }
                $human = $row['kind'] === Kind::HumanOrUnknown->value;
                $stage = !$human && is_string($row['stage'] ?? null) ? Stage::tryFrom($row['stage']) : null;
                $row['stage'] = $stage->value ?? '';
                $row['failure_mask'] = $human ? 0 : ((int) ($row['failure_mask'] ?? 0) & 4095);
                $row['known_mask'] = $human ? 0 : ((int) ($row['known_mask'] ?? 0) & 127);
                $row['order_id'] = !$human && is_string($row['order_id'] ?? null) && strlen($row['order_id']) === 16 ? $row['order_id'] : null;
                $row += ['discovery_resource' => '', 'heuristic_score' => 0, 'heuristic_flags' => 0, 'heuristic_version' => '', 'heuristic_threshold' => 0, 'heuristic_epoch' => ''];
                if ($new) {
                    $connection->insert('agentenblick_session', [
                        'id' => $sessionId, 'session_key' => $row['session_key'], 'key_day' => $row['day'],
                        'sales_channel_id' => $row['sales_channel_id'], 'first_seen' => $row['observed_at'],
                        'last_seen' => $row['observed_at'], 'operator' => $row['operator'], 'agent' => $row['agent'],
                        'kind' => $row['kind'], 'evidence' => $row['evidence'], 'linkage' => $row['linkage'], 'requests' => 1,
                        'steps_mask' => $stage?->bit() ?? 0, 'failures_mask' => $row['failure_mask'], 'known_mask' => $row['known_mask'],
                        'last_stage' => $row['stage'], 'last_stage_at' => $stage === null ? null : $row['observed_at'],
                        'heuristic_score' => $row['heuristic_score'], 'heuristic_flags' => $row['heuristic_flags'], 'heuristic_version' => $row['heuristic_version'],
                        'heuristic_threshold' => $row['heuristic_threshold'], 'heuristic_epoch' => $row['heuristic_epoch'],
                        'detected_at' => $row['evidence'] === 'suspected' ? $row['observed_at'] : null,
                        'journey_partial' => 0,
                    ]);
                } else {
                    $ranks = ['none' => 0, 'suspected' => 1, 'declared_unverified' => 2, 'verified_rdns' => 3, 'verified_ip' => 4, 'verified_signature' => 5];
                    $evidence = ($ranks[$row['evidence']] ?? 0) > ($ranks[$session['evidence']] ?? 0) ? $row['evidence'] : $session['evidence'];
                    $firstSeen = min($session['first_seen'], $row['observed_at']);
                    $connection->executeStatement('UPDATE agentenblick_session SET first_seen = LEAST(first_seen, ?), last_seen = GREATEST(last_seen, ?), requests = requests + 1, evidence = ? WHERE id = ?', [$row['observed_at'], $row['observed_at'], $evidence, $sessionId]);
                    $changes = [
                        'steps_mask' => (int) $session['steps_mask'] | ($stage?->bit() ?? 0),
                        'failures_mask' => (int) $session['failures_mask'] | $row['failure_mask'],
                        'known_mask' => (int) $session['known_mask'] | $row['known_mask'],
                    ];
                    $promoted = $session['kind'] === Kind::HumanOrUnknown->value && $row['evidence'] === 'suspected';
                    if ($promoted) {
                        $changes += ['kind' => Kind::AutomationUnknown->value, 'detected_at' => $row['observed_at'], 'journey_partial' => 1];
                    }
                    if ($session['evidence'] === 'suspected' && $human) {
                        $changes['journey_partial'] = 1;
                    }
                    if ($row['evidence'] === 'suspected' && ($row['heuristic_epoch'] !== $session['heuristic_epoch'] || $row['heuristic_score'] >= $session['heuristic_score'])) {
                        $changes += ['heuristic_score' => $row['heuristic_score'], 'heuristic_flags' => $row['heuristic_flags'], 'heuristic_version' => $row['heuristic_version'], 'heuristic_threshold' => $row['heuristic_threshold'], 'heuristic_epoch' => $row['heuristic_epoch']];
                    }
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
                    if ($session['kind'] !== Kind::HumanOrUnknown->value || $session['heuristic_epoch'] !== '') {
                        AggregateCorrection::move($connection, 'sessions', $from, array_replace($from, ['day' => substr($firstSeen, 0, 10), 'evidence' => $evidence], $changes));
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
                return $row;
            });
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** @param array<string, mixed> $row
     * @param array<string, mixed>|false $session
     * @return array<string, mixed>
     */
    private function assess(Connection $connection, array $row, array|false $session, string $sessionId, Candidate $candidate, \DateTimeImmutable $observedAt): array
    {
        $configuration = ($candidate->configuration)();
        if (!$configuration['enabled'] || ($session !== false && $session['kind'] === Kind::HumanOrUnknown->value && $session['heuristic_epoch'] === '')) {
            return $row;
        }
        if ($session !== false && is_string($session['detected_at']) && $row['observed_at'] < $session['detected_at']) {
            // Concurrent slow responses can finish after detection even though
            // they began earlier. Never backfill their previously human detail.
            return $row;
        }
        $name = 'heuristics:' . bin2hex($sessionId);
        $json = $connection->fetchOne('SELECT value FROM agentenblick_state WHERE name = ? AND updated_at >= ?', [$name, gmdate('Y-m-d H:i:s', time() - 48 * 3600)]);
        $old = is_string($json) ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : [];
        if (!is_array($old) || ($old['epoch'] ?? null) !== $configuration['epoch']) {
            $old = [];
        }
        $at = $observedAt->getTimestamp() * 1000 + (int) $observedAt->format('v');
        $history = History::advance($old, $candidate->flags, $candidate->cookiePresent, $candidate->cookieOffered, $at);
        $score = (new HeuristicScore())->evaluate($history['flags'], $history, $configuration['threshold']);
        $connection->executeStatement('INSERT INTO agentenblick_state (name, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$name, json_encode($history + ['epoch' => $configuration['epoch']], JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s')]);
        $inherited = $session !== false && $session['evidence'] === 'suspected' && $session['heuristic_epoch'] === $configuration['epoch'];
        if (!$score['suspected'] && !$inherited) {
            // Unknown sessions created during opt-in use an unsegmented source
            // bucket. Its opaque marker permits an exact later correction without
            // attaching a browser family to a person's temporary session record.
            if ($session === false || $session['kind'] === Kind::HumanOrUnknown->value) {
                $row['browser_family'] = '';
                $row['heuristic_epoch'] = $configuration['epoch'];
            }
            return $row;
        }
        $materialized = ($candidate->materialize)();
        return array_replace($materialized, [
            'reason' => $score['suspected'] ? 'heuristic_threshold' : 'heuristic_session_context',
            'heuristic_score' => $score['score'], 'heuristic_flags' => $score['flags'], 'heuristic_version' => $score['version'],
            'heuristic_threshold' => $configuration['threshold'], 'heuristic_epoch' => $configuration['epoch'],
        ]);
    }
}
