<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Integration;

use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Capture\Minimizer;
use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Heuristics\Candidate;
use Aithos\Agentenblick\Journey\JourneyReader;
use Aithos\Agentenblick\Journey\SessionCloser;
use Aithos\Agentenblick\Persistence\DailyKey;
use Aithos\Agentenblick\Persistence\ObservationStore;
use Aithos\Agentenblick\Persistence\Purger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

final class HeuristicsPersistenceTest extends TestCase
{
    private ?Connection $connection = null;
    private bool $enabled = true;
    private int $threshold = 60;
    private string $epoch = 'first';
    private int $materialized = 0;
    private int $base = 0;

    protected function setUp(): void
    {
        if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
            self::markTestSkipped('Requires the migrated disposable MySQL bench.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware'));
        foreach (['health_snapshot', 'health_daily', 'request', 'session', 'daily', 'journey_daily', 'state', 'key_cache'] as $name) {
            $definition = $this->db()->fetchAssociative('SHOW CREATE TABLE agentenblick_' . $name);
            self::assertNotFalse($definition);
            $this->db()->executeStatement('CREATE TEMPORARY TABLE ' . substr($definition['Create Table'], strlen('CREATE TABLE ')));
        }
        $this->base = time() * 1000 - 10000;
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testPromotionMovesExactlyOneSessionAndNeverReconstructsHumanDetail(): void
    {
        $store = new ObservationStore($this->db());
        for ($i = 0; $i < 5; ++$i) {
            $store->record($this->row($i), $this->candidate($i));
        }
        self::assertSame(0, $this->materialized);
        self::assertSame(0, $this->countRows('request'));
        self::assertSame(1, $this->aggregate('sessions', 'none'));
        self::assertSame('', $this->db()->fetchOne("SELECT browser_family FROM agentenblick_daily WHERE sessions = 1"));
        self::assertArrayNotHasKey('browser_family', $this->db()->fetchAssociative('SELECT * FROM agentenblick_session'));
        $state = (string) $this->db()->fetchOne('SELECT value FROM agentenblick_state');
        foreach (['private-query', 'raw-token', 'Mozilla', 'route', '192.0.2'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $state);
        }
        $store->record($this->row(5), $this->candidate(5));
        self::assertSame(1, $this->materialized);
        self::assertSame(1, $this->countRows('request'));
        self::assertSame(0, $this->aggregate('sessions', 'none'));
        self::assertSame(1, $this->aggregate('sessions', 'suspected'));
        self::assertSame(5, $this->aggregate('requests', 'none'));
        self::assertSame(1, $this->aggregate('requests', 'suspected'));
        self::assertSame(6, (int) $this->db()->fetchOne('SELECT requests FROM agentenblick_session'));
        $reader = new JourneyReader($this->db());
        $session = $reader->sessions(new ReportFilter())['items'][0];
        self::assertTrue($session['timelineStartsAtDetection']);
        self::assertTrue($session['observationPartial']);
        self::assertNotNull($session['detectedAt']);
        self::assertSame(70, $session['heuristics']['score']);
        self::assertContains('high_cadence', $session['heuristics']['flags']);
        $detail = $reader->detail($session['id'], new ReportFilter());
        self::assertCount(1, $detail['items']);
        self::assertSame('heuristic_threshold', $detail['items'][0]['reason']);
        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('raw-token', $json);
        self::assertStringNotContainsString('private-query', $json);
    }

    public function testDisabledAndChangedEpochDoNotContinueDetailedCollection(): void
    {
        $store = new ObservationStore($this->db());
        for ($i = 0; $i < 6; ++$i) {
            $store->record($this->row($i), $this->candidate($i));
        }
        $before = $this->db()->fetchOne('SELECT value FROM agentenblick_state');
        $this->enabled = false;
        $store->record($this->row(6), $this->candidate(6));
        self::assertSame(1, $this->countRows('request'));
        self::assertSame($before, $this->db()->fetchOne('SELECT value FROM agentenblick_state'));
        $this->enabled = true;
        $this->threshold = 100;
        $this->epoch = 'new-threshold';
        $store->record($this->row(7), $this->candidate(7));
        self::assertSame(1, $this->countRows('request'));
        $state = json_decode((string) $this->db()->fetchOne('SELECT value FROM agentenblick_state'), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(1, $state['requests']);
        self::assertSame('new-threshold', $state['epoch']);
        self::assertSame(1, $this->aggregate('sessions', 'suspected'));
        self::assertSame(7, $this->aggregate('requests', 'none'));
    }

    public function testDefaultOffDoesNotCreateStateOrMaterializeCandidate(): void
    {
        $this->enabled = false;
        (new ObservationStore($this->db()))->record($this->row(0), $this->candidate(0, 511));
        self::assertSame(0, $this->countRows('state'));
        self::assertSame(0, $this->countRows('request'));
        self::assertSame(0, $this->materialized);
        self::assertSame('chrome', $this->db()->fetchOne('SELECT browser_family FROM agentenblick_daily'));
        self::assertArrayNotHasKey('browser_family', $this->db()->fetchAssociative('SELECT * FROM agentenblick_session'));
    }

    public function testMaterializationFailureRollsBackFactsAndCountersTogether(): void
    {
        $candidate = new Candidate(511, false, false, static fn (): array => ['enabled' => true, 'threshold' => 60, 'version' => '1', 'epoch' => 'failure'], static function (): never {
            throw new \RuntimeException('fixture_materialization_failed');
        });
        try {
            (new ObservationStore($this->db()))->record($this->row(0), $candidate);
            self::fail('Expected a materialization failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('fixture_materialization_failed', $error->getMessage());
        }
        foreach (['request', 'session', 'daily', 'state'] as $table) {
            self::assertSame(0, $this->countRows($table));
        }
    }

    public function testKnownAgentOutranksHeuristicCandidate(): void
    {
        $row = array_replace($this->row(0), ['kind' => Kind::BrowsingAgent->value, 'operator' => 'test', 'agent' => 'Known', 'evidence' => Evidence::Ip->value, 'browser_family' => '']);
        (new ObservationStore($this->db()))->record($row, $this->candidate(0, 511));
        self::assertSame(0, $this->countRows('state'));
        self::assertSame(0, $this->materialized);
        self::assertSame('verified_ip', $this->db()->fetchOne('SELECT evidence FROM agentenblick_request'));
    }

    public function testLegacyBrowserFamilyFailsClosedAndFactsExpireAfter48Hours(): void
    {
        $store = new ObservationStore($this->db());
        $store->record($this->row(0));
        $store->record($this->row(1), $this->candidate(1, 511));
        self::assertSame(0, $this->countRows('request'));
        self::assertSame(0, $this->materialized);
        self::assertSame('human_or_unknown', $this->db()->fetchOne('SELECT kind FROM agentenblick_session'));
        self::assertSame(0, $this->countRows('state'));
        $store->record(array_replace($this->row(2), ['session_key' => hash('sha256', 'new-opted-in-session')]), $this->candidate(2));
        $this->db()->executeStatement('UPDATE agentenblick_state SET updated_at = ?', [gmdate('Y-m-d H:i:s', time() - 49 * 3600)]);
        $config = $this->createMock(SystemConfigService::class);
        $config->method('getInt')->willReturn(0);
        $result = (new Purger($this->db(), new DailyKey($this->db()), $config))->run();
        self::assertSame(1, $result['expired_state']);
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM agentenblick_state WHERE name LIKE 'heuristics:%'"));
    }

    public function testASlowEarlierResponseNeverBackfillsPreDetectionDetail(): void
    {
        $store = new ObservationStore($this->db());
        $store->record($this->row(5), $this->candidate(5, 511));
        $detectedAt = $this->db()->fetchOne('SELECT detected_at FROM agentenblick_session');
        $store->record($this->row(4), $this->candidate(4, 511));
        self::assertSame(1, $this->countRows('request'));
        self::assertSame(1, $this->materialized);
        self::assertSame($detectedAt, $this->db()->fetchOne('SELECT detected_at FROM agentenblick_session'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT journey_partial FROM agentenblick_session'));
        self::assertSame(1, $this->aggregate('requests', 'none'));
        self::assertSame(1, $this->aggregate('sessions', 'suspected'));
    }

    public function testInterruptingCollectionNeverInfersAbandonmentFromAMissingOrder(): void
    {
        $store = new ObservationStore($this->db());
        $candidate = new Candidate(511, false, false, fn (): array => ['enabled' => $this->enabled, 'threshold' => 60, 'version' => '1', 'epoch' => $this->epoch], fn (): array => array_replace($this->row(0, true), ['stage' => 'cart', 'known_mask' => 32, 'failure_mask' => 32]));
        $store->record($this->row(0), $candidate);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT journey_partial FROM agentenblick_session'));
        $this->enabled = false;
        $store->record(array_replace($this->row(1), ['stage' => 'order', 'known_mask' => 16, 'order_id' => random_bytes(16)]), $this->candidate(1));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT journey_partial FROM agentenblick_session'));
        (new SessionCloser($this->db()))->run(time() + 3600);
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COALESCE(SUM(sessions),0) FROM agentenblick_journey_daily WHERE metric LIKE '%abandoned_%'"));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT sessions FROM agentenblick_journey_daily WHERE metric = 'step.cart'"));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT sessions FROM agentenblick_journey_daily WHERE metric = 'failure.forbidden'"));
        self::assertSame(1, $this->countRows('request'));
    }

    /** @return array<string, mixed> */
    private function row(int $index, bool $suspected = false): array
    {
        $request = Request::create('https://shop.test/search?private-query=never-store', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.19']);
        $request->headers->set('User-Agent', 'Mozilla/5.0 Chrome/130.0.0.0 Safari/537.36');
        $request->headers->set('sw-context-token', 'raw-token');
        $request->attributes->set('sw-sales-channel-id', 'fffffffffffffffffffffffffffffff8');
        $request->attributes->set('_route', 'frontend.search.page');
        $milliseconds = $this->base + $index * 100;
        $at = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($milliseconds, 1000), ($milliseconds % 1000) * 1000));
        self::assertNotFalse($at);
        $classification = new Classification('unknown', 'unknown', $suspected ? Kind::AutomationUnknown : Kind::HumanOrUnknown, $suspected ? Evidence::Suspected : Evidence::None);
        return (new Minimizer())->minimize($request, $classification, str_repeat('h', 32), $at, 200);
    }

    private function candidate(int $index, int $flags = 8): Candidate
    {
        return new Candidate($flags, false, false, fn (): array => ['enabled' => $this->enabled, 'threshold' => $this->threshold, 'version' => '1', 'epoch' => $this->epoch], function () use ($index): array {
            ++$this->materialized;
            return $this->row($index, true);
        });
    }

    private function aggregate(string $counter, string $evidence): int
    {
        return (int) $this->db()->fetchOne('SELECT COALESCE(SUM(' . $counter . '), 0) FROM agentenblick_daily WHERE evidence = ?', [$evidence]);
    }

    private function countRows(string $table): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_' . $table);
    }

    private function db(): Connection
    {
        return $this->connection ?? throw new \LogicException('integration_database_unavailable');
    }
}
