<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Persistence\AggregateCorrection;
use Doctrine\DBAL\Connection;

final class PendingSignatures
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogueProvider $catalogues,
        private readonly KeyDirectory $directories,
    ) {
    }

    /** A bounded pass; key directories are fetched outside database transactions.
     * @return array<string, int>
     */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $result = ['checked' => 0, 'verified' => 0, 'deferred' => 0, 'rejected' => 0, 'expired' => 0, 'sessions_promoted' => 0];
        $result['expired'] = (int) $this->connection->executeStatement("UPDATE agentenblick_request SET pending_context = NULL, verify_after = NULL, reason = 'directory_retry_expired' WHERE pending_context IS NOT NULL AND (observed_at < ? OR verify_attempts >= 24)", [gmdate('Y-m-d H:i:s', $now - 86400)]);
        $catalogue = $this->catalogues->load();
        $rows = $this->connection->fetchAllAssociative('SELECT id, observed_at, pending_context, verify_after, verify_attempts FROM agentenblick_request WHERE pending_context IS NOT NULL AND verify_after <= ? ORDER BY verify_after LIMIT 50', [gmdate('Y-m-d H:i:s', $now)]);
        $started = microtime(true);
        foreach ($rows as $row) {
            if (microtime(true) - $started > 10) {
                break;
            }
            // A short lease prevents simultaneous CLI and scheduled workers fetching
            // the same directory for the same request; a crashed worker can be retried.
            if ($this->connection->executeStatement('UPDATE agentenblick_request SET verify_after = ? WHERE id = ? AND verify_after = ? AND pending_context IS NOT NULL', [gmdate('Y-m-d H:i:s', $now + 300), $row['id'], $row['verify_after']]) !== 1) {
                continue;
            }
            ++$result['checked'];
            try {
                $context = json_decode($row['pending_context'], true, 16, JSON_THROW_ON_ERROR);
                $request = (new SafeSignatureContext())->restore($context);
                // Expiry is checked against receipt, never the worker's later clock.
                $receivedAt = (new \DateTimeImmutable($row['observed_at'], new \DateTimeZone('UTC')))->getTimestamp();
                $classification = (new WebBotAuth())->verify($request, $catalogue, fn (string $origin): array => $this->directories->get($origin, $catalogue), $receivedAt);
                $this->apply($row['id'], $classification, $context, $now);
                ++$result[$classification->evidence === Evidence::Signature ? 'verified' : ($classification->reason === 'directory_unreachable' ? 'deferred' : 'rejected')];
            } catch (\JsonException|\UnexpectedValueException) {
                $this->connection->executeStatement("UPDATE agentenblick_request SET pending_context = NULL, verify_after = NULL, reason = 'invalid_replay_context' WHERE id = ?", [$row['id']]);
                ++$result['rejected'];
            }
        }
        $result['sessions_promoted'] = $this->promoteClosedSessions($now, $started);
        return $result;
    }

    /** @param array<string, mixed> $context */
    private function apply(string $id, Classification $classification, array $context, int $now): void
    {
        $this->connection->transactional(function (Connection $connection) use ($id, $classification, $context, $now): void {
            // Match the capture transaction's session -> request -> aggregate lock order.
            $sessionId = $connection->fetchOne('SELECT session_id FROM agentenblick_request WHERE id = ?', [$id]);
            if (is_string($sessionId)) {
                $connection->fetchOne('SELECT id FROM agentenblick_session WHERE id = ? FOR UPDATE', [$sessionId]);
            }
            $row = $connection->fetchAssociative('SELECT * FROM agentenblick_request WHERE id = ? FOR UPDATE', [$id]);
            if ($row === false || $row['pending_context'] === null) {
                return;
            }
            if ($classification->reason === 'directory_unreachable') {
                $delay = min(3600, 300 * (2 ** min(4, (int) $row['verify_attempts'] + 1)));
                $connection->executeStatement('UPDATE agentenblick_request SET verify_after = ?, verify_attempts = verify_attempts + 1 WHERE id = ?', [gmdate('Y-m-d H:i:s', $now + $delay), $id]);
                return;
            }
            $changes = ['pending_context' => null, 'verify_after' => null, 'verify_attempts' => (int) $row['verify_attempts'] + 1, 'reason' => $classification->reason];
            if ($classification->evidence === Evidence::Signature) {
                $changes += [
                    'operator' => $classification->operator, 'agent' => $classification->agent,
                    'kind' => $classification->kind->value, 'evidence' => $classification->evidence->value,
                    'key_id' => $classification->keyId,
                    'signature_input' => $context['headers']['Signature-Input'],
                    'signature_value' => $context['headers']['Signature'],
                    'signature_agent' => $context['headers']['Signature-Agent'],
                ];
                $changes['reason'] = 'valid_web_bot_auth_deferred';
                $from = $row + ['day' => substr($row['observed_at'], 0, 10)];
                AggregateCorrection::move($connection, 'requests', $from, array_replace($from, $changes));
                $connection->executeStatement('UPDATE agentenblick_session SET proof_review_after = GREATEST(DATE_ADD(last_seen, INTERVAL 1801 SECOND), ?) WHERE id = ?', [gmdate('Y-m-d H:i:s', $now), $row['session_id']]);
            }
            $connection->update('agentenblick_request', $changes, ['id' => $id]);
        });
    }

    private function promoteClosedSessions(int $now, float $started): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', $now - 1800);
        $candidates = $this->connection->fetchAllAssociative('SELECT id, session_key FROM agentenblick_session WHERE proof_review_after <= ? ORDER BY proof_review_after LIMIT 100', [gmdate('Y-m-d H:i:s', $now)]);
        $promoted = 0;
        foreach ($candidates as $candidate) {
            if (microtime(true) - $started > 10) {
                break;
            }
            $lock = 'agentenblick:' . substr($candidate['session_key'], 0, 48);
            if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
                continue;
            }
            try {
                $promoted += $this->connection->transactional(function (Connection $connection) use ($candidate, $cutoff): int {
                    $session = $connection->fetchAssociative('SELECT * FROM agentenblick_session WHERE id = ? FOR UPDATE', [$candidate['id']]);
                    if ($session === false) {
                        return 0;
                    }
                    if ($session['last_seen'] >= $cutoff) {
                        $connection->executeStatement('UPDATE agentenblick_session SET proof_review_after = DATE_ADD(last_seen, INTERVAL 1801 SECOND) WHERE id = ?', [$session['id']]);
                        return 0;
                    }
                    $connection->update('agentenblick_session', ['proof_review_after' => null], ['id' => $session['id']]);
                    if ($session['evidence'] === Evidence::Signature->value) {
                        return 0;
                    }
                    $proof = $connection->fetchAssociative("SELECT COUNT(*) AS total, SUM(evidence = 'verified_signature') AS verified, COUNT(DISTINCT key_id) AS keys_count, SUM(operator <> ? OR agent <> ? OR kind <> ?) AS identity_mismatch FROM agentenblick_request WHERE session_id = ?", [$session['operator'], $session['agent'], $session['kind'], $session['id']]);
                    // Missing/purged, mixed, or still live observations never gain a
                    // session-level proof through one successful deferred request.
                    if ($proof === false || (int) $proof['total'] !== (int) $session['requests'] || (int) $proof['verified'] !== (int) $session['requests'] || (int) $proof['keys_count'] !== 1 || (int) $proof['identity_mismatch'] !== 0) {
                        return 0;
                    }
                    $from = $session + ['day' => substr($session['first_seen'], 0, 10)];
                    AggregateCorrection::move($connection, 'sessions', $from, array_replace($from, ['evidence' => Evidence::Signature->value]));
                    $connection->update('agentenblick_session', ['evidence' => Evidence::Signature->value], ['id' => $session['id']]);
                    return 1;
                });
            } finally {
                $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
        return $promoted;
    }
}
