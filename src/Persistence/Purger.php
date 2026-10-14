<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Persistence;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class Purger
{
    public function __construct(private readonly Connection $connection, private readonly DailyKey $keys, private readonly SystemConfigService $config)
    {
    }

    /** @return array<string, int> */
    public function run(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $agentDays = max(1, min(365, $this->config->getInt('AithosAgentenblick.config.agentRetentionDays') ?: 90));
        $humanHours = max(1, min(168, $this->config->getInt('AithosAgentenblick.config.humanRetentionHours') ?: 48));
        $months = max(1, min(36, $this->config->getInt('AithosAgentenblick.config.aggregateRetentionMonths') ?: 13));
        $cutoff = $now->modify('-' . $agentDays . ' days')->format('Y-m-d H:i:s');
        $result = [
            'requests' => $this->connection->executeStatement('DELETE FROM agentenblick_request WHERE observed_at < ?', [$cutoff]),
            'agent_sessions' => $this->connection->executeStatement("DELETE FROM agentenblick_session WHERE kind <> 'human_or_unknown' AND last_seen < ?", [$cutoff]),
            'human_sessions' => $this->connection->executeStatement("DELETE FROM agentenblick_session WHERE kind = 'human_or_unknown' AND last_seen < ?", [$now->modify('-' . $humanHours . ' hours')->format('Y-m-d H:i:s')]),
            'daily' => $this->connection->executeStatement('DELETE FROM agentenblick_daily WHERE day < ?', [$now->modify('-' . $months . ' months')->format('Y-m-d')]),
            'expired_state' => $this->connection->executeStatement("DELETE FROM agentenblick_state WHERE name <> 'daily_hmac_key' AND updated_at < ?", [$now->modify('-48 hours')->format('Y-m-d H:i:s')]),
            'expired_keys' => $this->connection->executeStatement('DELETE FROM agentenblick_key_cache WHERE fetched_at < ?', [$now->modify('-24 hours')->format('Y-m-d H:i:s')]),
        ];
        $key = $this->keys->current($now->format('Y-m-d'));
        sodium_memzero($key);
        return $result;
    }
}
