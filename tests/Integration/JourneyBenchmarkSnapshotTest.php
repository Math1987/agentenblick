<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Integration;

use Aithos\Agentenblick\Benchmark\Drafts;
use Aithos\Agentenblick\Benchmark\Period;
use Aithos\Agentenblick\Benchmark\SecretVault;
use Aithos\Agentenblick\Benchmark\State;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\TransactionIsolationLevel;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/** Two real MySQL connections, with every application table mapped to disposable fixtures. */
final class JourneyBenchmarkSnapshotTest extends TestCase
{
    private ?Connection $writer = null;
    private ?JourneySnapshotConnection $reader = null;
    /** @var array<string, string> */
    private array $tables = [];
    private string $period;
    private State $state;

    protected function setUp(): void
    {
        if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
            self::markTestSkipped('Requires the migrated disposable MySQL bench.');
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware');
        $this->writer = DriverManager::getConnection($params);
        $reader = DriverManager::getConnection($params + ['wrapperClass' => JourneySnapshotConnection::class]);
        self::assertInstanceOf(JourneySnapshotConnection::class, $reader);
        $this->reader = $reader;
        $prefix = 'agentenblick_snapshot_' . bin2hex(random_bytes(6)) . '_';
        foreach (['daily', 'journey_daily', 'session', 'request', 'benchmark_draft'] as $suffix) {
            $source = 'agentenblick_' . $suffix;
            $this->tables[$source] = $prefix . $suffix;
            $this->writer->executeStatement('CREATE TABLE ' . $this->tables[$source] . ' LIKE ' . $source);
        }
        $minimal = [
            'system_config' => 'id BINARY(16) PRIMARY KEY, configuration_key VARCHAR(255) NOT NULL, configuration_value JSON NOT NULL, sales_channel_id BINARY(16) DEFAULT NULL, created_at DATETIME NOT NULL',
            'country' => 'id BINARY(16) PRIMARY KEY, iso CHAR(2) NOT NULL',
            'sales_channel' => 'id BINARY(16) PRIMARY KEY, country_id BINARY(16) NOT NULL, active TINYINT NOT NULL, type_id BINARY(16) NOT NULL, created_at DATETIME NOT NULL',
            'plugin' => 'name VARCHAR(255) PRIMARY KEY, active TINYINT NOT NULL',
        ];
        foreach ($minimal as $source => $definition) {
            $this->tables[$source] = $prefix . $source;
            $this->writer->executeStatement('CREATE TABLE ' . $this->tables[$source] . ' (' . $definition . ') ENGINE=InnoDB');
        }
        $reader->tables = $this->tables;
        $reader->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
        $config = $this->createMock(SystemConfigService::class);
        $config->method('set')->willReturnCallback(function (string $key, mixed $value) use ($reader): void {
            $reader->executeStatement('DELETE FROM system_config WHERE configuration_key = ?', [$key]);
            $reader->insert('system_config', ['id' => random_bytes(16), 'configuration_key' => $key, 'configuration_value' => json_encode(['_value' => $value], JSON_THROW_ON_ERROR), 'created_at' => gmdate('Y-m-d H:i:s')]);
        });
        $this->state = new State($reader, $config, new SecretVault('snapshot-test-kernel-secret'), 'test');
        // Two weeks ago also keeps this test independent of Monday's 45-minute barrier.
        $this->period = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-2 weeks')->format('o-\WW');
        [$day] = Period::bounds($this->period);
        $channel = 'fffffffffffffffffffffffffffffffb';
        $country = random_bytes(16);
        $this->writer->insert($this->tables['country'], ['id' => $country, 'iso' => 'DE']);
        $this->writer->insert($this->tables['sales_channel'], ['id' => hex2bin($channel), 'country_id' => $country, 'active' => 1, 'type_id' => hex2bin(Defaults::SALES_CHANNEL_TYPE_STOREFRONT), 'created_at' => $day . ' 00:00:00']);
        $dimensions = ['day' => $day, 'sales_channel_id' => $channel, 'operator' => 'test', 'agent' => 'SnapshotAgent', 'kind' => 'browsing_agent', 'evidence' => 'declared_unverified'];
        $this->writer->insert($this->tables['agentenblick_daily'], $dimensions + ['requests' => 10, 'sessions' => 10, 'browser_family' => '']);
        foreach (['sessions', 'step.cart'] as $metric) {
            $this->writer->insert($this->tables['agentenblick_journey_daily'], $dimensions + ['metric' => $metric, 'sessions' => 10]);
        }
        $snapshot = ['dimensions' => array_values($dimensions), 'metrics' => ['sessions', 'step.cart']];
        for ($i = 0; $i < 10; ++$i) {
            $session = $dimensions;
            unset($session['day']);
            $this->writer->insert($this->tables['agentenblick_session'], $session + [
                'id' => random_bytes(16), 'session_key' => hash('sha256', 'snapshot-session-' . $i), 'key_day' => $day,
                'first_seen' => $day . ' 12:00:00', 'last_seen' => $day . ' 12:00:00', 'linkage' => 'exact', 'requests' => 1,
                'steps_mask' => 16, 'known_mask' => 32, 'last_stage' => 'cart', 'last_stage_at' => $day . ' 12:00:00',
                'closed_at' => $day . ' 13:00:00', 'contribution' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->reader !== null) {
            while ($this->reader->isTransactionActive()) {
                $this->reader->rollBack();
            }
            $this->reader->close();
        }
        if ($this->writer !== null) {
            foreach (array_reverse($this->tables) as $table) {
                $this->writer->executeStatement('DROP TABLE IF EXISTS ' . $table);
            }
            $this->writer->close();
        }
    }

    public function testConcurrentPromotionCannotMixOldTrafficWithNewJourneyProof(): void
    {
        $reader = $this->reader ?? throw new \LogicException('Reader unavailable.');
        $writer = $this->writer ?? throw new \LogicException('Writer unavailable.');
        $settings = $this->state->prepare($this->state->read());
        $promoted = false;
        $reader->afterDailySelect = function () use ($writer, &$promoted): void {
            $writer->transactional(function (Connection $connection): void {
                foreach (['agentenblick_daily', 'agentenblick_journey_daily', 'agentenblick_session'] as $source) {
                    $connection->executeStatement("UPDATE " . $this->tables[$source] . " SET evidence = 'verified_signature' WHERE evidence = 'declared_unverified'");
                }
                $connection->executeStatement("UPDATE " . $this->tables['agentenblick_session'] . " SET contribution = JSON_SET(contribution, '$.dimensions[5]', 'verified_signature')");
            });
            $promoted = true;
        };
        $drafts = (new Drafts($reader, $this->state, '6.7.15.0'))->prepare($settings, $this->period);
        self::assertTrue($promoted, 'Writer must commit between the two aggregate SELECTs.');
        self::assertCount(1, $drafts);
        $payload = $drafts[0]['payload'];
        self::assertSame(100.0, $payload['shares']['byEvidence']['declared_unverified']);
        self::assertSame(0.0, $payload['shares']['byEvidence']['verified_signature']);
        self::assertSame(['denominator' => 'at_least_10', 'pct' => 100.0], $payload['funnel']['declared']['cart']);
        self::assertSame(['denominator' => 'below_10', 'pct' => 'suppressed'], $payload['funnel']['verified']['cart']);
        self::assertSame(10, (int) $writer->fetchOne("SELECT SUM(sessions) FROM " . $this->tables['agentenblick_daily'] . " WHERE evidence = 'verified_signature'"));
        self::assertSame(10, (int) $writer->fetchOne("SELECT SUM(sessions) FROM " . $this->tables['agentenblick_journey_daily'] . " WHERE evidence = 'verified_signature' AND metric = 'sessions'"));
        self::assertSame(10, (int) $writer->fetchOne("SELECT COUNT(*) FROM " . $this->tables['agentenblick_session'] . " WHERE evidence = 'verified_signature'"));
        self::assertFalse($reader->isTransactionActive());
        self::assertSame(TransactionIsolationLevel::READ_COMMITTED, $reader->getTransactionIsolation());
    }

    public function testFailureRollsBackFrozenDocumentsAndRestoresConnectionIsolation(): void
    {
        $reader = $this->reader ?? throw new \LogicException('Reader unavailable.');
        $writer = $this->writer ?? throw new \LogicException('Writer unavailable.');
        $settings = $this->state->prepare($this->state->read());
        $writer->executeStatement("UPDATE " . $this->tables['agentenblick_journey_daily'] . " SET sessions = 11 WHERE metric = 'step.cart'");
        try {
            (new Drafts($reader, $this->state, '6.7.15.0'))->prepare($settings, $this->period);
            self::fail('Inconsistent source counters must not produce a draft.');
        } catch (\RuntimeException $error) {
            self::assertSame('benchmark_journey_inconsistent', $error->getMessage());
        }
        self::assertFalse($reader->isTransactionActive());
        self::assertSame(TransactionIsolationLevel::READ_COMMITTED, $reader->getTransactionIsolation());
        self::assertSame(0, (int) $writer->fetchOne('SELECT COUNT(*) FROM ' . $this->tables['agentenblick_benchmark_draft']));
    }
}

/** Interception exists only in this integration test; production receives ordinary DBAL. */
final class JourneySnapshotConnection extends Connection
{
    /** @var array<string, string> */
    public array $tables = [];
    public ?\Closure $afterDailySelect = null;

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $daily = str_contains($sql, 'FROM agentenblick_daily WHERE day');
        $result = parent::executeQuery($this->mapped($sql), $params, $types, $qcp);
        if ($daily && $this->afterDailySelect !== null) {
            $callback = $this->afterDailySelect;
            $this->afterDailySelect = null;
            $callback();
        }
        return $result;
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return parent::executeStatement($this->mapped($sql), $params, $types);
    }

    private function mapped(string $sql): string
    {
        foreach ($this->tables as $source => $target) {
            $sql = preg_replace('/\b' . preg_quote($source, '/') . '\b/', $target, $sql) ?? throw new \LogicException('Invalid fixture table mapping.');
        }
        return $sql;
    }
}
