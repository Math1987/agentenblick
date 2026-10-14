<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Integration;

use Aithos\Agentenblick\Persistence\DailyKey;
use Aithos\Agentenblick\Persistence\ProxyDiagnostics;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/** A connection-local temporary state table protects every existing shop row. */
final class StatePersistenceTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
            self::markTestSkipped('Requires the migrated disposable MySQL bench.');
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware');
        $this->connection = DriverManager::getConnection($params);
        $definition = $this->db()->fetchAssociative('SHOW CREATE TABLE agentenblick_state');
        self::assertNotFalse($definition);
        self::assertStringStartsWith('CREATE TABLE ', $definition['Create Table']);
        $this->db()->executeStatement('CREATE TEMPORARY TABLE ' . substr($definition['Create Table'], strlen('CREATE TABLE ')));
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testProxyCountersStayExactAcrossCdnAndOtherRequests(): void
    {
        $diagnostics = new ProxyDiagnostics($this->db());
        foreach ([true, false, true, true, false, true, false, true, true, false] as $cdn) {
            $diagnostics->record($cdn);
        }
        $report = $diagnostics->report();
        self::assertSame(10, $report['requests']);
        self::assertSame(6, $report['cdn_resolved_client_ips']);
        self::assertTrue($report['warning']);
        self::assertSame(4, (int) $this->db()->fetchOne("SELECT value FROM agentenblick_state WHERE name LIKE 'proxy_noncdn:%'"));
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_state'));
    }

    public function testFailedSecondCounterCannotCommitOnlyTheTotal(): void
    {
        $diagnostics = new ProxyDiagnostics($this->db());
        $diagnostics->record(true);
        // Inject a database failure on the second row using a narrow fixture
        // column. The product uses text; this constraint is connection-local.
        $this->db()->executeStatement("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        $this->db()->executeStatement('ALTER TABLE agentenblick_state MODIFY value TINYINT UNSIGNED NOT NULL');
        $this->db()->executeStatement("UPDATE agentenblick_state SET value = 255 WHERE name LIKE 'proxy_cdn:%'");
        try {
            $diagnostics->record(true);
            self::fail('The fixture overflow must reject the second CDN increment.');
        } catch (Exception) {
            self::assertSame(1, $diagnostics->report()['requests']);
            self::assertSame(255, $diagnostics->report()['cdn_resolved_client_ips']);
        }
        $diagnostics->record(false);
        self::assertSame(2, $diagnostics->report()['requests']);
        self::assertSame(255, $diagnostics->report()['cdn_resolved_client_ips']);
    }

    public function testLongLivedServiceReadsAReplacedKeyInsteadOfCachingIt(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $keys = new DailyKey($this->db());
        $initial = $keys->current($today->format('Y-m-d'));
        self::assertSame(32, strlen($initial));
        $replacement = str_repeat('r', 32);
        $newerDay = $today->modify('+1 day')->format('Y-m-d');
        $this->db()->executeStatement("UPDATE agentenblick_state SET value = ?, updated_at = ? WHERE name = 'daily_hmac_key'", [base64_encode($replacement), $newerDay . ' 00:00:00']);

        self::assertSame($replacement, $keys->current($today->modify('-1 day')->format('Y-m-d')));
        self::assertSame($newerDay . ' 00:00:00', $this->db()->fetchOne("SELECT updated_at FROM agentenblick_state WHERE name = 'daily_hmac_key'"));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_state'));
    }

    public function testInvalidCurrentKeyIsNotSilentlyRegenerated(): void
    {
        $this->db()->insert('agentenblick_state', ['name' => 'daily_hmac_key', 'value' => '!invalid!', 'updated_at' => gmdate('Y-m-d') . ' 00:00:00']);
        try {
            (new DailyKey($this->db()))->current(gmdate('Y-m-d'));
            self::fail('A corrupt current key must fail closed.');
        } catch (\RuntimeException $error) {
            self::assertSame('invalid_hmac_key', $error->getMessage());
        }
        self::assertSame('!invalid!', $this->db()->fetchOne("SELECT value FROM agentenblick_state WHERE name = 'daily_hmac_key'"));
    }

    private function db(): Connection
    {
        return $this->connection ?? throw new \LogicException('integration_database_unavailable');
    }
}
