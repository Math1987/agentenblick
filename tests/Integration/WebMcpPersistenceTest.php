<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Integration;

use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Capture\Minimizer;
use Aithos\Agentenblick\Capture\SalesChannelResolver;
use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Journey\JourneyReader;
use Aithos\Agentenblick\Persistence\DailyKey;
use Aithos\Agentenblick\Persistence\ObservationStore;
use Aithos\Agentenblick\WebMcp\Declaration;
use Aithos\Agentenblick\WebMcp\Declarations;
use Aithos\Agentenblick\WebMcp\Scope;
use Aithos\Agentenblick\WebMcp\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

final class WebMcpPersistenceTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
            self::markTestSkipped('Requires the migrated disposable MySQL bench.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware'));
        foreach (['request', 'session', 'daily', 'journey_daily', 'state'] as $name) {
            $definition = $this->db()->fetchAssociative('SHOW CREATE TABLE agentenblick_' . $name);
            self::assertNotFalse($definition);
            $this->db()->executeStatement('CREATE TEMPORARY TABLE ' . substr($definition['Create Table'], strlen('CREATE TABLE ')));
        }
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testDeclarationPromotesOneExactSessionOnceWithoutInventingRequests(): void
    {
        $request = $this->request('native-one');
        $at = $this->now()->modify('-2 seconds');
        $store = new ObservationStore($this->db());
        $store->record($this->row($request, $at));
        $store->record($this->row($request, $at->modify('+1 second')));
        self::assertSame(1, $this->aggregate('sessions', 'none'));
        self::assertSame('', $this->db()->fetchOne('SELECT browser_family FROM agentenblick_daily'));
        self::assertSame(0, $this->count('request'));
        $service = $this->declarations();
        $declaration = new Declaration('codex', 'product_search');
        $service->declare($request, $declaration);
        $service->declare($request, $declaration);
        self::assertSame(1, $this->count('session'));
        self::assertSame(0, $this->count('request'));
        self::assertSame(0, $this->aggregate('sessions', 'none'));
        self::assertSame(1, $this->aggregate('sessions', 'declared_unverified'));
        self::assertSame(2, $this->aggregate('requests', 'none'));
        self::assertSame(0, $this->aggregate('requests', 'declared_unverified'));
        $key = (new DailyKey($this->db()))->current(gmdate('Y-m-d'));
        $later = $this->now()->modify('+1 second');
        $classification = $service->classify($request, new Classification(), $key, $later);
        self::assertSame('Codex', $classification->agent);
        $store->record($this->row($request, $later, $classification));
        self::assertSame(1, $this->count('session'));
        self::assertSame(1, $this->count('request'));
        self::assertSame(1, $this->aggregate('requests', 'declared_unverified'));
        self::assertSame('', $this->db()->fetchOne('SELECT user_agent FROM agentenblick_request'));
        $session = (new JourneyReader($this->db()))->sessions(new ReportFilter())['items'][0];
        self::assertSame('webmcp_self_declaration', $session['reason']);
        self::assertTrue($session['observationPartial']);
        self::assertTrue($session['timelineStartsAtDetection']);
        self::assertSame('codex', $session['declaration']['service']);
        self::assertSame('product_search', $session['declaration']['intent']);
    }

    public function testSharedFingerprintNeverPromotesAnotherBrowserOrLegacySession(): void
    {
        $at = $this->now()->modify('-1 second');
        $first = $this->request('first-native-cookie');
        $second = $this->request('second-native-cookie');
        $store = new ObservationStore($this->db());
        $store->record($this->row($first, $at));
        $store->record($this->row($second, $at));
        $legacy = $this->request('');
        $store->record($this->row($legacy, $at));
        $this->declarations()->declare($first, new Declaration('claude', 'product_comparison'));
        self::assertSame(3, $this->count('session'));
        self::assertSame(2, $this->aggregate('sessions', 'none'));
        self::assertSame(1, $this->aggregate('sessions', 'declared_unverified'));
        $this->expectExceptionMessage('session_unavailable');
        $this->declarations()->declare($legacy, new Declaration('codex', 'other'));
    }

    public function testStrongerEvidenceIsNotDowngradedOrRelabelled(): void
    {
        $request = $this->request('verified-native');
        $verified = new Classification('test', 'Verified fixture', Kind::BrowsingAgent, Evidence::Ip, 'verified_fixture');
        $store = new ObservationStore($this->db());
        $store->record($this->row($request, $this->now()->modify('-1 second'), $verified));
        $this->declarations()->declare($request, new Declaration('grok', 'other'));
        self::assertSame(1, $this->aggregate('sessions', 'verified_ip'));
        self::assertSame(0, $this->aggregate('sessions', 'declared_unverified'));
        self::assertSame('Verified fixture', $this->db()->fetchOne('SELECT agent FROM agentenblick_session'));
        self::assertSame('grok', $this->db()->fetchOne('SELECT declared_service FROM agentenblick_session'));
        $key = (new DailyKey($this->db()))->current(gmdate('Y-m-d'));
        self::assertSame($verified, $this->declarations()->classify($request, $verified, $key, $this->now()));
    }

    public function testLaterStrongerProofMovesTheSessionWithoutCountingItAgain(): void
    {
        $request = $this->request('upgraded-native');
        $store = new ObservationStore($this->db());
        $store->record($this->row($request, $this->now()->modify('-1 second')));
        $this->declarations()->declare($request, new Declaration('codex', 'other'));
        $verified = new Classification('test', 'Verified fixture', Kind::BrowsingAgent, Evidence::Signature, 'verified_fixture');
        $store->record($this->row($request, $this->now()->modify('+1 second'), $verified));
        self::assertSame(1, $this->count('session'));
        self::assertSame(1, $this->aggregate('sessions', 'verified_signature'));
        self::assertSame(0, $this->aggregate('sessions', 'declared_unverified'));
        self::assertSame('Verified fixture', $this->db()->fetchOne('SELECT agent FROM agentenblick_session'));
        self::assertSame('codex', $this->db()->fetchOne('SELECT declared_service FROM agentenblick_session'));
    }

    public function testConflictingRepeatIsRejectedAndExpiredSessionCannotBeReused(): void
    {
        $request = $this->request('repeat-native');
        (new ObservationStore($this->db()))->record($this->row($request, $this->now()->modify('-1 second')));
        $this->declarations()->declare($request, new Declaration('codex', 'other'));
        try {
            $this->declarations()->declare($request, new Declaration('claude', 'other'));
            self::fail('Expected a conflicting declaration to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('declaration_conflict', $error->getMessage());
        }
        self::assertSame('codex', $this->db()->fetchOne('SELECT declared_service FROM agentenblick_session'));
        $this->expectExceptionMessage('session_unavailable');
        $this->declarations()->declare($request, new Declaration('codex', 'other'), $this->now()->modify('+31 minutes'));
    }

    public function testLateEarlierResponseDoesNotReconstructPreDeclarationDetail(): void
    {
        $request = $this->request('late-native');
        $at = $this->now()->modify('-3 seconds');
        $store = new ObservationStore($this->db());
        $store->record($this->row($request, $at));
        $this->declarations()->declare($request, new Declaration('codex', 'other'));
        $key = (new DailyKey($this->db()))->current(gmdate('Y-m-d'));
        $classification = $this->declarations()->classify($request, new Classification(), $key, $at->modify('+1 second'));
        self::assertSame(Kind::HumanOrUnknown, $classification->kind);
        $store->record($this->row($request, $at->modify('+1 second'), $classification));
        self::assertSame(1, $this->count('session'));
        self::assertSame(0, $this->count('request'));
        self::assertSame(1, $this->aggregate('sessions', 'declared_unverified'));
        self::assertSame(2, $this->aggregate('requests', 'none'));
    }

    private function declarations(): Declarations
    {
        return new Declarations($this->db(), new DailyKey($this->db()), $this->scope());
    }

    private function scope(): Scope
    {
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getBool')->willReturn(true);
        return new Scope(new Settings($config), new SalesChannelResolver($this->db()));
    }

    private function request(string $cookie): Request
    {
        $request = Request::create('https://shop.test/product?never-retain=secret-query', cookies: $cookie === '' ? [] : ['session-' => $cookie], server: ['REMOTE_ADDR' => '192.0.2.5']);
        $request->attributes->set('sw-sales-channel-id', str_repeat('a', 32));
        $request->attributes->set('sw-domain-id', str_repeat('b', 32));
        $request->headers->set('User-Agent', 'Mozilla/5.0 Chrome/130.0 private-browser');
        $this->scope()->prepare($request);
        return $request;
    }

    /** @return array<string, mixed> */
    private function row(Request $request, \DateTimeImmutable $at, ?Classification $classification = null): array
    {
        $key = (new DailyKey($this->db()))->current($at->format('Y-m-d'));
        return (new Minimizer())->minimize($request, $classification ?? new Classification(), $key, $at, 200);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function aggregate(string $counter, string $evidence): int
    {
        return (int) $this->db()->fetchOne('SELECT COALESCE(SUM(' . $counter . '), 0) FROM agentenblick_daily WHERE evidence = ?', [$evidence]);
    }

    private function count(string $table): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_' . $table);
    }

    private function db(): Connection
    {
        return $this->connection ?? throw new \LogicException('integration_database_unavailable');
    }
}
