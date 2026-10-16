<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Benchmark;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/** @phpstan-type Settings array{enabled: bool, installationId: string, secretCipher: string, registered: bool, pendingWithdrawal: bool, demo: bool, transportVersion: int, channels: array<string, string>, consentAt: ?string, locale: ?string, consentVersion: ?string} */
final class State
{
    public const KEY = 'AithosAgentenblick.internal.benchmark';
    public const TRANSPORT_VERSION = 2;

    public function __construct(private readonly Connection $connection, private readonly SystemConfigService $config, private readonly SecretVault $vault, private readonly string $environment = 'prod')
    {
    }

    /** Reads under the DB advisory lock bypass the per-request system config cache.
     * @return Settings
     */
    public function read(): array
    {
        $raw = $this->value(self::KEY);
        $raw = is_array($raw) ? $raw : [];
        $installationId = is_string($raw['installationId'] ?? null) ? $raw['installationId'] : '';
        // Pin an existing identity; a new one follows current configuration after withdrawal.
        $demo = $installationId !== '' ? ($raw['demo'] ?? null) === true : ($this->environment === 'test' || $this->value('AithosAgentenblick.config.benchmarkDemo') === true || $this->config->getBool('AithosAgentenblick.config.benchmarkDemo'));
        $transportVersion = is_int($raw['transportVersion'] ?? null) ? $raw['transportVersion'] : 1;
        if ($installationId !== '' && !is_bool($raw['demo'] ?? null)) {
            $transportVersion = 0;
        }
        $channels = [];
        foreach (is_array($raw['channels'] ?? null) ? $raw['channels'] : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $channels[$key] = $value;
            }
        }
        return [
            'enabled' => ($raw['enabled'] ?? false) === true,
            'installationId' => $installationId,
            'secretCipher' => is_string($raw['secretCipher'] ?? null) ? $raw['secretCipher'] : '',
            'registered' => ($raw['registered'] ?? false) === true,
            'pendingWithdrawal' => ($raw['pendingWithdrawal'] ?? false) === true,
            'demo' => $demo,
            'transportVersion' => $transportVersion,
            'channels' => $channels,
            'consentAt' => is_string($raw['consentAt'] ?? null) ? $raw['consentAt'] : null,
            'locale' => is_string($raw['locale'] ?? null) ? $raw['locale'] : null,
            'consentVersion' => is_string($raw['consentVersion'] ?? null) ? $raw['consentVersion'] : null,
        ];
    }

    /** @param Settings $state */
    public function save(array $state): void
    {
        $this->config->set(self::KEY, $state);
    }

    /** @param Settings $state
     * @return Settings
     */
    public function prepare(array $state): array
    {
        if ($this->testIdentityConflict($state)) {
            throw new \RuntimeException('benchmark_test_identity_conflict');
        }
        if ($this->transportMigrationRequired($state)) {
            throw new \RuntimeException('benchmark_transport_migration_required');
        }
        if ($state['installationId'] === '') {
            $state['installationId'] = Payload::uuid();
            $state['secretCipher'] = $this->vault->encrypt(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
            $state['transportVersion'] = self::TRANSPORT_VERSION;
            $this->save($state);
        }
        return $state;
    }

    /** @param Settings $state */
    public function secret(array $state): string
    {
        return $this->vault->decrypt($state['secretCipher']);
    }

    public function offline(): bool
    {
        // Managed Symfony configuration can force offline without a database row.
        // A fresh database refusal also wins over a stale per-request cache.
        return $this->value('AithosAgentenblick.config.offline') === true || $this->config->getBool('AithosAgentenblick.config.offline');
    }

    /** A cloned production identity must never operate on real contributions in test.
     * @param Settings $state
     */
    public function testIdentityConflict(array $state): bool
    {
        return $this->environment === 'test' && !$state['demo'];
    }

    /** Legacy demo credentials belong to the former shared service, not the new one.
     * @param Settings $state
     */
    public function transportMigrationRequired(array $state): bool
    {
        return $state['installationId'] !== '' && (!in_array($state['transportVersion'], [1, self::TRANSPORT_VERSION], true) || ($state['demo'] && $state['transportVersion'] !== self::TRANSPORT_VERSION));
    }

    private function value(string $key): mixed
    {
        $stored = $this->connection->fetchOne('SELECT configuration_value FROM system_config WHERE configuration_key = ? AND sales_channel_id IS NULL', [$key]);
        if (!is_string($stored)) {
            return null;
        }
        $decoded = json_decode($stored, true, 64, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? ($decoded['_value'] ?? null) : null;
    }
}
