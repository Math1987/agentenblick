<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Integration;

use Aithos\Agentenblick\Capture\Minimizer;
use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Journey\SessionCloser;
use Aithos\Agentenblick\Persistence\DailyKey;
use Aithos\Agentenblick\Persistence\ObservationStore;
use Aithos\Agentenblick\Persistence\Purger;
use Aithos\Agentenblick\Verification\CatalogueProvider;
use Aithos\Agentenblick\Verification\HttpSignature;
use Aithos\Agentenblick\Verification\Jwk;
use Aithos\Agentenblick\Verification\KeyDirectory;
use Aithos\Agentenblick\Verification\PendingSignatures;
use Aithos\Agentenblick\Verification\SafeSignatureContext;
use Aithos\Agentenblick\Verification\WebBotAuth;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Opt in on the disposable migrated bench only:
 * AGENTENBLICK_DB_TEST=1 vendor/bin/phpunit -c path/to/plugin/phpunit.xml path/to/plugin/tests/Integration
 * MySQL temporary tables shadow the real tables for this connection, including
 * global key/purge state. Closing the connection discards every test mutation.
 */
final class PersistenceTest extends TestCase
{
    private const CHANNEL = 'fffffffffffffffffffffffffffffffa';

    private ?Connection $connection = null;
    private mixed $oldCatalogue = null;
    private bool $catalogueWasSet = false;

    protected function setUp(): void
    {
        if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
            self::markTestSkipped('Requires AGENTENBLICK_DB_TEST=1 on the disposable MySQL bench.');
        }
        $this->catalogueWasSet = array_key_exists('AGENTENBLICK_TEST_CATALOG', $_SERVER);
        $this->oldCatalogue = $_SERVER['AGENTENBLICK_TEST_CATALOG'] ?? null;
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware');
        $this->connection = DriverManager::getConnection($params);
        foreach (['health_snapshot', 'health_daily', 'request', 'session', 'daily', 'journey_daily', 'state', 'key_cache', 'ip_ranges'] as $name) {
            $table = 'agentenblick_' . $name;
            $definition = $this->connection->fetchAssociative('SHOW CREATE TABLE ' . $table);
            self::assertNotFalse($definition);
            $ddl = $definition['Create Table'];
            self::assertIsString($ddl);
            self::assertStringStartsWith('CREATE TABLE ', $ddl);
            $this->connection->executeStatement('CREATE TEMPORARY TABLE ' . substr($ddl, strlen('CREATE TABLE ')));
        }
        $_SERVER['AGENTENBLICK_TEST_CATALOG'] = dirname(__DIR__) . '/fixtures/catalog.json';
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->close();
            if ($this->catalogueWasSet) {
                $_SERVER['AGENTENBLICK_TEST_CATALOG'] = $this->oldCatalogue;
            } else {
                unset($_SERVER['AGENTENBLICK_TEST_CATALOG']);
            }
        }
    }

    public function testLateTerminationCannotRegressSessionLastSeen(): void
    {
        $at = $this->now();
        $store = new ObservationStore($this->db());
        $store->record($this->row($at));
        $store->record($this->row($at->modify('+2 minutes')));
        $store->record($this->row($at->modify('-5 minutes')));

        $session = $this->db()->fetchAssociative('SELECT * FROM agentenblick_session');
        self::assertNotFalse($session);
        self::assertSame($at->modify('-5 minutes')->format('Y-m-d H:i:s.v'), $session['first_seen']);
        self::assertSame($at->modify('+2 minutes')->format('Y-m-d H:i:s.v'), $session['last_seen']);
        self::assertSame(3, (int) $session['requests']);
        self::assertSame(1, $this->countRows('session'));
        self::assertSame(['requests' => 3, 'sessions' => 1], $this->totals());
    }

    public function testStrongerProofMovesOneSessionWithoutReclassifyingItsRequests(): void
    {
        $at = $this->now();
        $store = new ObservationStore($this->db());
        foreach ([Evidence::Declared, Evidence::Ip, Evidence::Rdns, Evidence::Ip] as $index => $proof) {
            $store->record($this->row($at->modify('+' . $index . ' minutes'), $proof));
        }

        self::assertSame(Evidence::Ip->value, $this->db()->fetchOne('SELECT evidence FROM agentenblick_session'));
        self::assertSame(['requests' => 4, 'sessions' => 1], $this->totals());
        self::assertSame(['requests' => 2, 'sessions' => 1], $this->totals(Evidence::Ip));
        self::assertSame(['requests' => 1, 'sessions' => 0], $this->totals(Evidence::Declared));
        self::assertSame(['requests' => 1, 'sessions' => 0], $this->totals(Evidence::Rdns));
        self::assertSame(4, $this->countRows('request'));
    }

    public function testThirtyMinutesOfInactivityStartsAnotherSession(): void
    {
        $at = $this->now();
        $store = new ObservationStore($this->db());
        $store->record($this->row($at));
        $store->record($this->row($at->modify('+29 minutes')));
        $store->record($this->row($at->modify('+60 minutes')));

        self::assertSame(2, $this->countRows('session'));
        self::assertSame(['requests' => 3, 'sessions' => 2], $this->totals());
        self::assertSame([1, 2], array_map('intval', $this->db()->fetchFirstColumn('SELECT requests FROM agentenblick_session ORDER BY requests')));
    }

    public function testHumanTrafficKeepsOnlySessionAndAggregateCounters(): void
    {
        $at = $this->now();
        $store = new ObservationStore($this->db());
        $store->record($this->row($at, human: true));
        $store->record($this->row($at->modify('+1 minute'), human: true));

        self::assertSame(0, $this->countRows('request'));
        self::assertSame(1, $this->countRows('session'));
        self::assertSame(['requests' => 2, 'sessions' => 1], $this->totals());
        $session = $this->db()->fetchAssociative('SELECT * FROM agentenblick_session');
        self::assertNotFalse($session);
        $stored = json_encode($session, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        foreach (['203.0.113.19', 'private-context', 'private-cookie', 'private-query', 'personal-browser-build'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $stored);
        }
    }

    public function testDailyKeyRotatesForwardAndNeverRecreatesAnEarlierKey(): void
    {
        $keys = new DailyKey($this->db());
        $today = $this->now();
        $old = $keys->current($today->format('Y-m-d'));
        $new = $keys->current($today->modify('+1 day')->format('Y-m-d'));

        self::assertSame(32, strlen($old));
        self::assertSame(32, strlen($new));
        self::assertNotSame($old, $new);
        self::assertSame($new, $keys->current($today->modify('-1 day')->format('Y-m-d')));
        self::assertSame($today->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $this->db()->fetchOne("SELECT updated_at FROM agentenblick_state WHERE name = 'daily_hmac_key'"));
        self::assertSame(1, $this->countRows('state'));
    }

    public function testPurgeDeletesOnlyExpiredDataAndPreservesTheCurrentKey(): void
    {
        $at = $this->now();
        $store = new ObservationStore($this->db());
        $store->record($this->row($at->modify('-100 days')));
        $store->record($this->row($at));
        $store->record($this->row($at->modify('-3 days'), human: true));
        $store->record($this->row($at, human: true));
        $this->db()->insert('agentenblick_daily', [
            'day' => $at->modify('-14 months')->format('Y-m-d'), 'sales_channel_id' => self::CHANNEL,
            'operator' => 'test', 'agent' => 'old-aggregate', 'kind' => Kind::SearchCrawler->value,
            'evidence' => Evidence::Declared->value, 'browser_family' => '', 'requests' => 4, 'sessions' => 2,
        ]);
        $this->db()->insert('agentenblick_state', ['name' => 'old-test-state', 'value' => '{}', 'updated_at' => $at->modify('-49 hours')->format('Y-m-d H:i:s')]);
        $this->db()->insert('agentenblick_key_cache', ['origin' => 'https://old.test', 'document_json' => '{"keys":[]}', 'fetched_at' => $at->modify('-25 hours')->format('Y-m-d H:i:s')]);
        $keys = new DailyKey($this->db());
        $current = $keys->current($at->format('Y-m-d'));
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getInt')->willReturn(0);

        $result = (new Purger($this->db(), $keys, $config))->run();

        self::assertSame(['requests' => 1, 'agent_sessions' => 1, 'human_sessions' => 1, 'daily' => 1, 'expired_state' => 1, 'expired_keys' => 1, 'closure_failed' => 0], $result);
        self::assertSame(1, $this->countRows('request'));
        self::assertSame(2, $this->countRows('session'));
        self::assertSame(1, $this->countRows('state'));
        self::assertSame($current, $keys->current($at->format('Y-m-d')));
    }

    public function testDeferredVerificationCorrectsCountersOnceUsingReceiptTime(): void
    {
        $received = $this->now()->modify('-2 hours');
        [$request, $key] = $this->signedRequest($received->getTimestamp());
        $catalogues = new CatalogueProvider('test');
        $catalogue = $catalogues->load();
        $classification = (new WebBotAuth())->verify($request, $catalogue, static fn () => throw new \RuntimeException('directory unavailable'), $received->getTimestamp());
        self::assertSame('directory_unreachable', $classification->reason);
        $context = (new SafeSignatureContext())->capture($request, $catalogue, $classification);
        self::assertNotNull($context);
        $row = (new Minimizer())->minimize($request, $classification, str_repeat('a', 32), $received, 200, $context);
        (new ObservationStore($this->db()))->record($row);
        (new SessionCloser($this->db()))->run();
        $this->db()->insert('agentenblick_key_cache', [
            'origin' => 'https://signature-agent.test',
            'document_json' => json_encode(['keys' => [$key]], JSON_THROW_ON_ERROR),
            'fetched_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getBool')->willReturn(true);
        $pending = new PendingSignatures($this->db(), $catalogues, new KeyDirectory($this->db(), $config, 'test'));

        $first = $pending->run();
        self::assertSame(1, $first['checked']);
        self::assertSame(1, $first['verified']);
        self::assertSame(1, $first['sessions_promoted']);
        self::assertSame(['requests' => 1, 'sessions' => 1], $this->totals(Evidence::Signature));
        self::assertSame(['requests' => 0, 'sessions' => 0], $this->totals(Evidence::Declared));
        self::assertSame('valid_web_bot_auth_deferred', $this->db()->fetchOne('SELECT reason FROM agentenblick_request'));
        self::assertNull($this->db()->fetchOne('SELECT pending_context FROM agentenblick_request'));
        self::assertSame(Jwk::thumbprint($key), $this->db()->fetchOne('SELECT key_id FROM agentenblick_request'));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT sessions FROM agentenblick_journey_daily WHERE metric = 'sessions' AND evidence = 'verified_signature'"));

        $second = $pending->run();
        self::assertSame(0, array_sum($second));
        self::assertSame(['requests' => 1, 'sessions' => 1], $this->totals());
    }

    /** @return array<string, mixed> */
    private function row(\DateTimeImmutable $at, Evidence $proof = Evidence::Declared, bool $human = false): array
    {
        $request = Request::create('https://shop.test/?secret=private-query', 'GET', [], ['session-test' => 'private-cookie'], [], ['REMOTE_ADDR' => '203.0.113.19']);
        $request->headers->set('sw-context-token', 'private-context');
        $request->headers->set('User-Agent', $human ? 'Mozilla/5.0 personal-browser-build' : 'IntegrationAgent/1.0');
        $request->attributes->set('sw-sales-channel-id', self::CHANNEL);
        $classification = $human ? new Classification() : new Classification('test', 'IntegrationAgent', Kind::SearchCrawler, $proof);
        return (new Minimizer())->minimize($request, $classification, str_repeat('a', 32), $at, 200);
    }

    /** @return array{Request, array<string, string>} */
    private function signedRequest(int $created): array
    {
        $pair = sodium_crypto_sign_keypair();
        $key = ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => Jwk::encode(sodium_crypto_sign_publickey($pair))];
        $request = Request::create('https://shop.test/private/person@example.test?secret=private-query');
        $request->attributes->set('sw-sales-channel-id', self::CHANNEL);
        $request->headers->set('Signature-Agent', '"https://signature-agent.test"');
        $input = 'sig2=("@authority" "signature-agent");created=' . $created . ';expires=' . ($created + 60) . ';keyid="' . Jwk::thumbprint($key) . '";alg="ed25519";tag="web-bot-auth"';
        $request->headers->set('Signature-Input', $input);
        $http = new HttpSignature();
        $candidate = $http->parse($input, 'sig2=:YWJj:')['sig2'];
        $signature = sodium_crypto_sign_detached($http->base($request, $candidate['input']), sodium_crypto_sign_secretkey($pair));
        $request->headers->set('Signature', 'sig2=:' . base64_encode($signature) . ':');
        return [$request, $key];
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(gmdate('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    private function db(): Connection
    {
        return $this->connection ?? throw new \LogicException('integration_database_unavailable');
    }

    private function countRows(string $table): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_' . $table);
    }

    /** @return array{requests: int, sessions: int} */
    private function totals(?Evidence $proof = null): array
    {
        $row = $this->db()->fetchAssociative('SELECT COALESCE(SUM(requests), 0) AS requests, COALESCE(SUM(sessions), 0) AS sessions FROM agentenblick_daily' . ($proof === null ? '' : ' WHERE evidence = ?'), $proof === null ? [] : [$proof->value]);
        self::assertNotFalse($row);
        return ['requests' => (int) $row['requests'], 'sessions' => (int) $row['sessions']];
    }
}
