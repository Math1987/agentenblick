<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Integration;

use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Capture\Minimizer;
use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Journey\JourneyReader;
use Aithos\Agentenblick\Journey\SessionCloser;
use Aithos\Agentenblick\Persistence\AggregateCorrection;
use Aithos\Agentenblick\Persistence\DailyKey;
use Aithos\Agentenblick\Persistence\ObservationStore;
use Aithos\Agentenblick\Persistence\Purger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class JourneyTest extends TestCase
{
    private ?Connection $connection = null;
    private const CHANNEL = 'fffffffffffffffffffffffffffffffa';

    protected function setUp(): void
    {
        if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
            self::markTestSkipped('Requires the migrated disposable MySQL bench.');
        }
        $this->connection = $this->connect();
        foreach (['request', 'session', 'daily', 'journey_daily', 'state', 'key_cache'] as $name) {
            $definition = $this->db()->fetchAssociative('SHOW CREATE TABLE agentenblick_' . $name);
            self::assertNotFalse($definition);
            $this->db()->executeStatement('CREATE TEMPORARY TABLE ' . substr($definition['Create Table'], strlen('CREATE TABLE ')));
        }
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testClosureIsIdempotentAndStepsAreIndependent(): void
    {
        $at = time() - 7200;
        $store = new ObservationStore($this->db());
        $store->record($this->row($at, 'cart'));
        $closer = new SessionCloser($this->db());
        self::assertSame(1, $closer->run()['closed']);
        self::assertSame(0, $closer->run()['closed']);
        self::assertSame(1, $this->metricCount('sessions'));
        self::assertSame(1, $this->metricCount('step.cart'));
        self::assertSame(0, $this->metricCount('step.product'));
        self::assertSame(1, $this->metricCount('failure.abandoned_cart'));
        self::assertSame(0, $this->metricCount('failure.abandoned_checkout'));
    }

    public function testLateOrderCorrectsAbandonmentAndLastStageDoesNotRegress(): void
    {
        $at = time() - 7200;
        $store = new ObservationStore($this->db());
        $store->record($this->row($at, 'cart'));
        $store->record($this->row($at + 60, 'checkout'));
        (new SessionCloser($this->db()))->run();
        self::assertSame(1, $this->metricCount('failure.abandoned_checkout'));
        $order = random_bytes(16);
        $store->record($this->row($at + 120, 'order', ['order_id' => $order]));
        $store->record($this->row($at - 60, 'browse'));
        self::assertSame(0, $this->metricCount('failure.abandoned_checkout'));
        self::assertSame(0, $this->metricCount('failure.abandoned_cart'));
        self::assertSame(1, $this->metricCount('step.order'));
        self::assertSame(1, $this->metricCount('sessions'));
        self::assertSame('order', $this->db()->fetchOne('SELECT last_stage FROM agentenblick_session'));
        self::assertSame(bin2hex($order), $this->db()->fetchOne('SELECT LOWER(HEX(order_id)) FROM agentenblick_request WHERE order_id IS NOT NULL'));
    }

    public function testRecentLateRequestReopensThenClosesOnce(): void
    {
        $now = time();
        $store = new ObservationStore($this->db());
        $store->record($this->row($now - 3000, 'cart'));
        $closer = new SessionCloser($this->db());
        $closer->run($now);
        self::assertSame(1, $this->metricCount('sessions'));
        $store->record($this->row($now - 1500, 'checkout'));
        self::assertNull($this->db()->fetchOne('SELECT closed_at FROM agentenblick_session'));
        self::assertSame(0, $this->metricCount('sessions'));
        self::assertSame(1, $closer->run($now + 601)['closed']);
        self::assertSame(1, $this->metricCount('sessions'));
        self::assertSame(1, $this->metricCount('failure.abandoned_checkout'));
        self::assertSame(0, $this->metricCount('failure.abandoned_cart'));
    }

    public function testStrongProofAndEarlierDayMoveAllClosedContributionsExactlyOnce(): void
    {
        $at = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-2 days +10 minutes')->getTimestamp();
        $store = new ObservationStore($this->db());
        $store->record($this->row($at, 'search', ['failure_mask' => 1, 'known_mask' => 33]));
        (new SessionCloser($this->db()))->run();
        $store->record($this->row($at - 1200, 'browse', ['evidence' => 'verified_ip']));
        self::assertSame(0, $this->metricCount('sessions', 'declared_unverified'));
        self::assertSame(1, $this->metricCount('sessions', 'verified_ip'));
        self::assertSame(1, $this->metricCount('failure.search_empty', 'verified_ip'));
        self::assertSame(gmdate('Y-m-d', $at - 1200), $this->db()->fetchOne("SELECT day FROM agentenblick_journey_daily WHERE metric = 'sessions' AND sessions = 1"));
        $this->db()->transactional(function (Connection $connection): void {
            $session = $connection->fetchAssociative('SELECT * FROM agentenblick_session FOR UPDATE');
            self::assertNotFalse($session);
            $from = $session + ['day' => substr($session['first_seen'], 0, 10)];
            AggregateCorrection::move($connection, 'sessions', $from, array_replace($from, ['evidence' => 'verified_signature']));
            $connection->update('agentenblick_session', ['evidence' => 'verified_signature'], ['id' => $session['id']]);
        });
        self::assertSame(0, $this->metricCount('sessions', 'verified_ip'));
        self::assertSame(1, $this->metricCount('sessions', 'verified_signature'));
        self::assertSame(1, $this->metricCount('failure.search_empty', 'verified_signature'));
    }

    public function testHumanSignalsNeverReachDetailOrJourneyCounters(): void
    {
        $store = new ObservationStore($this->db());
        $store->record($this->row(time() - 7200, 'order', ['kind' => 'human_or_unknown', 'agent' => 'unknown', 'evidence' => 'none', 'failure_mask' => 4095, 'known_mask' => 63, 'order_id' => random_bytes(16)]));
        (new SessionCloser($this->db()))->run();
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_request'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_journey_daily'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT steps_mask + failures_mask + known_mask FROM agentenblick_session'));
        $reader = new JourneyReader($this->db());
        self::assertSame([], $reader->sessions(new ReportFilter())['items']);
        $id = (string) $this->db()->fetchOne('SELECT LOWER(HEX(id)) FROM agentenblick_session');
        $this->expectException(NotFoundHttpException::class);
        $reader->detail($id, new ReportFilter());
    }

    public function testUnknownOutcomesDoNotBecomeSuccessDenominatorsAndReaderIsMinimised(): void
    {
        $store = new ObservationStore($this->db());
        $store->record($this->row(time() - 7200, 'search'));
        (new SessionCloser($this->db()))->run();
        self::assertSame(0, $this->metricCount('known.search_empty'));
        $reader = new JourneyReader($this->db());
        $summary = $reader->summary(new ReportFilter());
        $failure = array_values(array_filter($summary['failures'], static fn (array $row): bool => $row['code'] === 'search_empty' && $row['evidence'] === 'declared_unverified'))[0];
        self::assertNull($failure['rate']);
        $session = $reader->sessions(new ReportFilter())['items'][0];
        self::assertSame('search', $session['maxStage']);
        $detail = $reader->detail($session['id'], new ReportFilter());
        self::assertSame(['http'], $detail['items'][0]['knownOutcomes']);
        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        foreach (['ip_hmac', 'session_key', 'signature_input', 'private-token', 'secret-query'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
    }

    public function testCloserSkipsAConcurrentCaptureLockAndRetries(): void
    {
        (new ObservationStore($this->db()))->record($this->row(time() - 7200, 'cart'));
        $key = (string) $this->db()->fetchOne('SELECT session_key FROM agentenblick_session');
        $lock = 'agentenblick:' . substr($key, 0, 48);
        $other = $this->connect();
        try {
            self::assertSame(1, (int) $other->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]));
            $result = (new SessionCloser($this->db()))->run();
            self::assertSame(0, $result['closed']);
            self::assertSame(1, $result['busy']);
            self::assertSame(1, $result['remaining']);
        } finally {
            $other->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            $other->close();
        }
        self::assertSame(1, (new SessionCloser($this->db()))->run()['closed']);
    }

    public function testPurgeClosesBeforeDeletingOrderDetailsAndRetainsOnlyRecentAggregates(): void
    {
        $store = new ObservationStore($this->db());
        $store->record($this->row(time() - 100 * 86400, 'order', ['order_id' => random_bytes(16)]));
        $old = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-14 months')->getTimestamp();
        $store->record($this->row($old, 'cart'));
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getInt')->willReturn(0);
        $result = (new Purger($this->db(), new DailyKey($this->db()), $config))->run();
        self::assertSame(2, $result['requests']);
        self::assertSame(2, $result['agent_sessions']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_request'));
        self::assertSame(1, $this->metricCount('sessions'));
        self::assertSame(1, $this->metricCount('step.order'));
        self::assertSame(0, $this->metricCount('step.cart'));
    }

    public function testPurgeNeverRetainsExpiredDetailsBeyondTheClosureBatch(): void
    {
        $store = new ObservationStore($this->db());
        $at = time() - 100 * 86400;
        for ($index = 0; $index < 201; ++$index) {
            $store->record($this->row($at, 'cart', ['session_key' => hash('sha256', 'expired-' . $index)]));
        }
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getInt')->willReturn(0);
        $result = (new Purger($this->db(), new DailyKey($this->db()), $config))->run();
        self::assertSame(201, $result['requests']);
        self::assertSame(201, $result['agent_sessions']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_request'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_session'));
        self::assertLessThanOrEqual(200, $this->metricCount('sessions'));
        self::assertGreaterThan(0, $this->metricCount('sessions'));
    }

    public function testClosureFailureCannotPreventPrivacyPurge(): void
    {
        $at = time() - 100 * 86400;
        (new ObservationStore($this->db()))->record($this->row($at, 'cart'));
        $snapshot = ['dimensions' => [gmdate('Y-m-d', $at), self::CHANNEL, 'test', 'JourneyAgent', 'browsing_agent', 'declared_unverified'], 'metrics' => ['sessions']];
        // An inconsistent prior contribution forces reconciliation to fail. It
        // must not prevent retention deletion, and must not expose its contents.
        $this->db()->executeStatement('UPDATE agentenblick_session SET contribution = ?', [json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getInt')->willReturn(0);
        $result = (new Purger($this->db(), new DailyKey($this->db()), $config))->run();
        self::assertSame(1, $result['closure_failed']);
        self::assertSame(1, $result['requests']);
        self::assertSame(1, $result['agent_sessions']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_request'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_session'));
    }

    public function testOrderCreationDoesNotClaimThatPaymentWasEvaluated(): void
    {
        $at = time() - 7200;
        $store = new ObservationStore($this->db());
        $store->record($this->row($at, 'order', ['known_mask' => 16 | 32]));
        $closer = new SessionCloser($this->db());
        $closer->run();
        self::assertSame(0, $this->metricCount('known.payment_error'));
        $reader = new JourneyReader($this->db());
        $id = $reader->sessions(new ReportFilter())['items'][0]['id'];
        self::assertSame(['order', 'http'], $reader->detail($id, new ReportFilter())['items'][0]['knownOutcomes']);
        $store->record($this->row($at, 'checkout', ['session_key' => hash('sha256', 'payment-evaluated'), 'known_mask' => 32 | 64, 'failure_mask' => 512]));
        $closer->run();
        self::assertSame(1, $this->metricCount('known.payment_error'));
        self::assertSame(1, $this->metricCount('failure.payment_error'));
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function row(int $timestamp, string $stage, array $changes = []): array
    {
        $request = Request::create('https://shop.test/?secret-query=private');
        $request->headers->set('sw-context-token', 'private-token');
        $request->attributes->set('sw-sales-channel-id', self::CHANNEL);
        $row = (new Minimizer())->minimize($request, new Classification('test', 'JourneyAgent', Kind::BrowsingAgent, Evidence::Declared), str_repeat('j', 32), (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC')), 200);
        return array_replace($row, ['stage' => $stage, 'known_mask' => 32, 'failure_mask' => 0, 'order_id' => null], $changes);
    }

    private function metricCount(string $metric, ?string $proof = null): int
    {
        return (int) $this->db()->fetchOne('SELECT COALESCE(SUM(sessions), 0) FROM agentenblick_journey_daily WHERE metric = ?' . ($proof === null ? '' : ' AND evidence = ?'), $proof === null ? [$metric] : [$metric, $proof]);
    }

    private function db(): Connection
    {
        return $this->connection ?? throw new \LogicException('integration_database_unavailable');
    }

    private function connect(): Connection
    {
        return DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware'));
    }
}
