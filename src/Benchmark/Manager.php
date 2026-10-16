<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Benchmark;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @phpstan-import-type Settings from State */
final class Manager
{
    public const CONSENT_VERSION = '2';
    public function __construct(private readonly Connection $connection, private readonly State $settings, private readonly Drafts $drafts, private readonly Transport $transport)
    {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $state = $this->settings->read();
        $offline = $this->settings->offline();
        return [
            'enabled' => $state['enabled'], 'offline' => $offline, 'pendingWithdrawal' => $state['pendingWithdrawal'], 'registered' => $state['registered'],
            'demo' => $state['demo'], 'installationId' => $state['installationId'] === '' ? null : $state['installationId'],
            'consentAt' => $state['consentAt'], 'locale' => $state['locale'], 'consentVersion' => $state['consentVersion'],
            'requiredConsentVersion' => self::CONSENT_VERSION,
            'reasonCode' => $this->settings->testIdentityConflict($state) ? 'test_identity_conflict' : ($this->settings->transportMigrationRequired($state) ? 'transport_migration_required' : ($state['pendingWithdrawal'] ? 'withdrawal_pending' : ($offline ? 'offline' : (!$state['enabled'] ? 'disabled' : ($state['consentVersion'] !== self::CONSENT_VERSION ? 'consent_update_required' : (!$state['registered'] ? 'registration_pending' : 'ready')))))),
        ];
    }

    /** @return array<string, mixed> */
    public function preview(?string $period = null): array
    {
        return $this->locked(function () use ($period): array {
            $period ??= Period::previous();
            $state = $this->settings->prepare($this->settings->read());
            return ['period' => $period, 'items' => $this->drafts->prepare($state, $period), 'networkSent' => false];
        });
    }

    /** @return array<string, mixed> */
    public function history(): array
    {
        $this->drafts->purge();
        return ['items' => $this->drafts->rows(null)];
    }

    /** @return array<string, mixed> */
    public function enable(string $locale): array
    {
        if (!in_array($locale, ['de-DE', 'en-GB'], true)) {
            throw new \InvalidArgumentException('Supported consent locale required.');
        }
        return $this->locked(function () use ($locale): array {
            $state = $this->settings->prepare($this->settings->read());
            if ($state['pendingWithdrawal']) {
                throw new ConflictHttpException('Complete the pending withdrawal before opting in again.');
            }
            $state['enabled'] = true;
            $state['consentVersion'] = self::CONSENT_VERSION;
            $state['consentAt'] = gmdate('c');
            $state['locale'] = $locale;
            $this->settings->save($state);
            if (!$this->settings->offline() && !$state['registered']) {
                $this->register($state);
            }
            return $this->status();
        });
    }

    /** Persist the refusal before attempting deletion; never discard its secret on failure.
     * @return array<string, mixed>
     */
    public function disable(): array
    {
        return $this->locked(function (): array {
            $state = $this->settings->read();
            $state['enabled'] = false;
            $state['pendingWithdrawal'] = $state['installationId'] !== '' && $state['consentAt'] !== null;
            $this->settings->save($state);
            if ($state['pendingWithdrawal'] && !$this->settings->offline() && !$this->settings->testIdentityConflict($state) && !$this->settings->transportMigrationRequired($state)) {
                $this->withdraw($state);
            }
            return $this->status();
        });
    }

    /** One bounded HTTP operation under the lock keeps withdrawal responsive.
     * @return array<string, mixed>
     */
    public function send(): array
    {
        return $this->locked(function (): array {
            $state = $this->settings->read();
            if ($this->settings->offline()) {
                return ['status' => 'offline'];
            }
            if ($this->settings->testIdentityConflict($state)) {
                return ['status' => 'test_identity_conflict'];
            }
            if ($this->settings->transportMigrationRequired($state)) {
                return ['status' => 'transport_migration_required'];
            }
            if ($state['pendingWithdrawal']) {
                $this->withdraw($state);
                return ['status' => $this->settings->read()['pendingWithdrawal'] ? 'withdrawal_pending' : 'withdrawn'];
            }
            if (!$state['enabled']) {
                return ['status' => 'disabled'];
            }
            if ($state['consentVersion'] !== self::CONSENT_VERSION) {
                return ['status' => 'consent_update_required'];
            }
            if (!$state['registered']) {
                $this->register($state);
                return ['status' => $this->settings->read()['registered'] ? 'registered' : 'registration_pending'];
            }
            $this->drafts->prepare($state, Period::previous());
            $row = $this->drafts->next($state['installationId']);
            if ($row === null) {
                return ['status' => 'no_pending_documents'];
            }
            if (!is_string($row['json']) || !hash_equals((string) $row['sha256'], hash('sha256', $row['json']))) {
                throw new \RuntimeException('benchmark_draft_integrity_failed');
            }
            $this->drafts->result($state['installationId'], $row, 'pending', 'sending');
            $secret = $this->settings->secret($state);
            try {
                $response = $this->transport->request('POST', '/v1/submissions', $row['json'], $state['installationId'], $secret, $state['demo']);
                $sent = in_array($response['status'], [200, 201], true) && ($response['body']['accepted'] ?? false) === true;
                $code = $sent ? 'accepted' : 'http_' . $response['status'];
                if ($response['status'] === 429 && ($response['body']['error'] ?? null) === 'weekly_channel_limit') {
                    $code = 'weekly_channel_limit';
                }
            } catch (\Throwable) {
                $sent = false;
                $code = 'transport_failed';
            } finally {
                sodium_memzero($secret);
            }
            $terminal = in_array($code, ['http_400', 'http_409', 'http_410', 'http_413', 'http_422', 'weekly_channel_limit'], true);
            $this->drafts->result($state['installationId'], $row, $sent ? 'sent' : ($terminal ? 'rejected' : 'failed'), $code);
            return ['status' => $sent ? 'sent' : 'failed', 'period' => $row['period'], 'channelId' => $row['channelId'], 'resultCode' => $code, 'retryable' => !$sent && !$terminal];
        });
    }

    /** @return array<string, mixed> */
    public function comparison(): array
    {
        return $this->locked(function (): array {
            $state = $this->settings->read();
            if (!$state['enabled'] || $state['consentVersion'] !== self::CONSENT_VERSION || $state['pendingWithdrawal'] || $this->settings->offline() || $this->settings->testIdentityConflict($state) || $this->settings->transportMigrationRequired($state)) {
                return ['available' => false, 'reason' => 'benchmark_not_connected'];
            }
            if ($state['demo']) {
                return ['available' => false, 'reason' => 'demo_dashboard_authentication_required'];
            }
            $period = Period::previous();
            $rows = $this->drafts->prepare($state, $period);
            if ($rows === []) {
                return ['available' => false, 'reason' => 'no_local_data'];
            }
            $first = $rows[0]['payload'];
            if (!is_array($first) || !is_array($first['segment'] ?? null)) {
                throw new \RuntimeException('benchmark_draft_integrity_failed');
            }
            $segment = $first['segment'];
            $cohort = implode('|', [(string) $segment['country'], (string) $segment['shopware'], (string) $segment['trafficBucket'], $segment['agenticCommerceExtension'] ? '1' : '0']);
            try {
                $response = $this->transport->request('GET', '/v1/benchmarks?' . http_build_query(['period' => $period, 'segment' => $cohort], '', '&', PHP_QUERY_RFC3986), demo: $state['demo']);
                if ($response['status'] !== 200 || ($response['body']['demo'] ?? null) !== false) {
                    return ['available' => false, 'reason' => 'benchmark_unavailable'];
                }
                $report = Comparison::sanitize($response['body'], $period, $cohort);
                $local = array_map(static function (array $row) use ($cohort): array {
                    $payload = $row['payload'];
                    return ['period' => $row['period'], 'channelId' => $row['channelId'], 'cohortId' => $cohort, 'aiSessionsPct' => is_array($payload) && is_array($payload['shares'] ?? null) ? ($payload['shares']['aiSessionsPct'] ?? 'suppressed') : 'suppressed'];
                }, $rows);
                return ['available' => true, 'report' => $report, 'local' => $local];
            } catch (\Throwable) {
                return ['available' => false, 'reason' => 'benchmark_unavailable'];
            }
        });
    }

    /** Called daily; retries deletion even after opt-out. At most twenty documents.
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $this->drafts->purge();
        $last = ['status' => 'no_pending_documents'];
        for ($i = 0; $i < 20; ++$i) {
            $last = $this->send();
            if ($last['status'] === 'failed' && ($last['retryable'] ?? true) === false) {
                continue;
            }
            if (!in_array($last['status'], ['sent', 'registered'], true)) {
                break;
            }
        }
        return $last;
    }

    /** @param Settings $state */
    private function register(array $state): void
    {
        $secret = $this->settings->secret($state);
        try {
            $response = $this->transport->request('POST', '/v1/installations', Payload::bytes(['installationId' => $state['installationId'], 'secret' => $secret, 'demo' => $state['demo']]), demo: $state['demo']);
            if (in_array($response['status'], [200, 201], true) && ($response['body']['installationId'] ?? null) === $state['installationId']) {
                $state['registered'] = true;
                $this->settings->save($state);
            }
        } catch (\Throwable) {
            // Registration remains retryable with the same ID and encrypted secret.
        } finally {
            sodium_memzero($secret);
        }
    }

    /** @param Settings $state */
    private function withdraw(array $state): void
    {
        $secret = $this->settings->secret($state);
        try {
            $response = $this->transport->request('DELETE', '/v1/installations/' . $state['installationId'], '', $state['installationId'], $secret, $state['demo']);
            if ($response['status'] === 204) {
                $this->drafts->withdrawn($state['installationId']);
                $state['pendingWithdrawal'] = false;
                $state['registered'] = false;
                $state['installationId'] = '';
                $state['secretCipher'] = '';
                $state['channels'] = [];
                $state['consentAt'] = null;
                $state['consentVersion'] = null;
                $state['locale'] = null;
                $this->settings->save($state);
            }
        } catch (\Throwable) {
            // Opt-out is already committed. Keep the secret only for retrying DELETE.
        } finally {
            sodium_memzero($secret);
        }
    }

    /** @template T
     * @param callable(): T $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
        $name = 'agentenblick.benchmark.' . substr(hash('sha256', (string) $this->connection->getDatabase()), 0, 24);
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 2)', [$name]) !== 1) {
            throw new ConflictHttpException('A benchmark operation is in progress; retry shortly.');
        }
        try {
            return $callback();
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
}
