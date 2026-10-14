<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Integration;

use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Health\Domains;
use Aithos\Agentenblick\Health\HealthReader;
use Aithos\Agentenblick\Health\Observation;
use Aithos\Agentenblick\Health\ProbeService;
use Aithos\Agentenblick\Health\ProbeTokens;
use Aithos\Agentenblick\Health\ProbeTransport;
use Aithos\Agentenblick\Health\RobotsPolicy;
use Aithos\Agentenblick\Health\SnapshotStore;
use Aithos\Agentenblick\Verification\CatalogueProvider;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** All SQL tables are isolated; real shop observations and settings are never changed. */
final class HealthTest extends TestCase
{
    private const CHANNEL = 'fffffffffffffffffffffffffffffff7';
    private const DOMAIN = 'fffffffffffffffffffffffffffffff8';
    private const URL = 'https://example.com';
    private ?Connection $writer = null;
    private HealthFixtureConnection $db;
    /** @var array<string, string> */
    private array $tables = [];
    private Domains $domains;
    private SnapshotStore $snapshots;
    private ProbeTokens $tokens;
    private SystemConfigService $config;
    private bool $offline = false;

    protected function setUp(): void
    {
        if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
            self::markTestSkipped('Requires the migrated disposable MySQL bench.');
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware');
        $this->writer = DriverManager::getConnection($params);
        $db = DriverManager::getConnection($params + ['wrapperClass' => HealthFixtureConnection::class]);
        self::assertInstanceOf(HealthFixtureConnection::class, $db);
        $this->db = $db;
        $prefix = 'agentenblick_health_test_' . bin2hex(random_bytes(5)) . '_';
        foreach (['request', 'state', 'health_snapshot', 'health_daily'] as $suffix) {
            $source = 'agentenblick_' . $suffix;
            $this->tables[$source] = $prefix . $suffix;
            $this->writer->executeStatement('CREATE TABLE ' . $this->tables[$source] . ' LIKE ' . $source);
        }
        foreach (['sales_channel' => 'id BINARY(16) PRIMARY KEY, active TINYINT NOT NULL', 'sales_channel_domain' => 'id BINARY(16) PRIMARY KEY, sales_channel_id BINARY(16) NOT NULL, url VARCHAR(2048) NOT NULL'] as $source => $definition) {
            $this->tables[$source] = $prefix . $source;
            $this->writer->executeStatement('CREATE TABLE ' . $this->tables[$source] . ' (' . $definition . ') ENGINE=InnoDB');
        }
        $db->tables = $this->tables;
        $db->insert('sales_channel', ['id' => hex2bin(self::CHANNEL), 'active' => 1]);
        $db->insert('sales_channel_domain', ['id' => hex2bin(self::DOMAIN), 'sales_channel_id' => hex2bin(self::CHANNEL), 'url' => self::URL]);
        $this->domains = new Domains($db);
        $this->snapshots = new SnapshotStore($db);
        $this->tokens = new ProbeTokens($db);
        $this->config = $this->createMock(SystemConfigService::class);
        $this->config->method('getDomain')->willReturnCallback(fn (): array => ['AithosAgentenblick.config.offline' => $this->offline]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        if ($this->writer !== null) {
            foreach (array_reverse($this->tables) as $table) {
                $this->writer->executeStatement('DROP TABLE IF EXISTS ' . $table);
            }
            $this->writer->close();
        }
    }

    public function testCapabilitiesAreBoundOneUseExpiringAndNeverStoredInPlaintext(): void
    {
        $token = $this->tokens->issue('GET', self::URL . '/robots.txt', 'GPTBot');
        $raw = (string) $this->db->fetchOne('SELECT value FROM agentenblick_state');
        self::assertStringNotContainsString($token, $raw);
        $request = Request::create(self::URL . '/robots.txt', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'GPTBot', 'HTTP_X_AGENTENBLICK_HEALTH_PROBE' => $token]);
        foreach (['method', 'scheme', 'port', 'host', 'path', 'agent', 'signature'] as $change) {
            $invalid = clone $request;
            match ($change) {
                'method' => $invalid->setMethod('POST'),
                'scheme' => $invalid->server->set('HTTPS', 'off'),
                'port' => $invalid->headers->set('Host', 'example.com:8443'),
                'host' => $invalid->headers->set('Host', 'other.example.com'),
                'path' => $invalid->server->set('REQUEST_URI', '/other'),
                'agent' => $invalid->headers->set('User-Agent', 'ClaudeBot'),
                'signature' => $invalid->headers->set(ProbeTokens::HEADER, substr($token, 0, -1) . (str_ends_with($token, '0') ? '1' : '0')),
            };
            self::assertFalse($this->tokens->consume($invalid), $change);
        }
        self::assertTrue($this->tokens->consume($request));
        self::assertFalse($this->tokens->consume($request));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM agentenblick_state'));
        $expired = $this->tokens->issue('GET', self::URL . '/robots.txt', 'GPTBot');
        $this->db->executeStatement("UPDATE agentenblick_state SET value = JSON_SET(value, '$.expires', ?)", [time() - 1]);
        $request->headers->set(ProbeTokens::HEADER, $expired);
        self::assertFalse($this->tokens->consume($request));
        $this->tokens->revoke($expired);
    }

    public function testExplicitProbeSeparatesHttpResultsRulesAndOptionalServerUserAgentChecks(): void
    {
        $calls = [];
        $transport = $this->transport(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url];
            self::assertSame(0, $options['max_redirects']);
            self::assertLessThanOrEqual(2.0, $options['max_duration']);
            self::assertFalse($options['buffer']);
            $headers = $options['normalized_headers'];
            $token = trim(substr($headers['x-agentenblick-health-probe'][0], strlen(ProbeTokens::HEADER) + 1));
            $agent = trim(substr($headers['user-agent'][0], strlen('User-Agent') + 1));
            $incoming = Request::create($url, $method, [], [], [], ['HTTP_USER_AGENT' => $agent, 'HTTP_X_AGENTENBLICK_HEALTH_PROBE' => $token]);
            self::assertTrue($this->tokens->consume($incoming), 'Exclusion is available before the response and final revocation.');
            $path = parse_url($url, PHP_URL_PATH);
            $status = match ($path) {
                '/.well-known/ucp' => 500, '/store-api/_mcp' => 403, '/agents.md' => 404, default => 200
            };
            $mime = in_array($path, ['/.well-known/ucp', '/.well-known/agent-card.json'], true) ? 'application/json' : 'text/plain';
            return new MockResponse($path === '/robots.txt' ? "User-agent: GPTBot\nDisallow: /blocked\nAllow: /\n" : '', ['http_code' => $status, 'response_headers' => ['content-type: ' . $mime]]);
        });
        $probes = new ProbeService($this->db, $this->domains, $transport, $this->snapshots, new CatalogueProvider('test'));
        self::assertTrue($probes->run(self::DOMAIN)['completed']);
        self::assertCount(8, $calls);
        $snapshot = $this->snapshots->load(self::DOMAIN)['snapshot'];
        self::assertSame([], $snapshot['autoTests']);
        self::assertSame('parsed', $snapshot['robots']['state']);
        $resources = array_column($snapshot['resources'], null, 'key');
        self::assertSame('missing', $resources['agents_md']['state']);
        self::assertSame('error', $resources['ucp']['state']);
        self::assertSame(403, $resources['mcp']['statusCode']);
        self::assertSame('OPTIONS', $resources['mcp']['probeMethod']);
        self::assertTrue($resources['mcp']['protocolNotValidated']);
        $calls = [];
        $this->db->executeStatement('UPDATE sales_channel_domain SET url = ?', [self::URL . '/de']);
        $probes->run(self::DOMAIN, true);
        self::assertSame(['GET', self::URL . '/robots.txt'], $calls[0]);
        self::assertSame(['GET', self::URL . '/de/llms.txt'], $calls[1]);
        self::assertSame(['GET', self::URL . '/.well-known/ucp'], $calls[5]);
        self::assertSame(['OPTIONS', self::URL . '/de/store-api/_mcp'], $calls[7]);
        self::assertSame(['GET', self::URL . '/de/'], $calls[8]);
        self::assertCount(11, $calls);
        $auto = $this->snapshots->load(self::DOMAIN)['snapshot']['autoTests'];
        self::assertSame(['GPTBot', 'ClaudeBot', 'ChatGPT-User'], array_column($auto, 'agent'));
        self::assertSame(['server_ip_not_operator'], array_values(array_unique(array_column($auto, 'source'))));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM agentenblick_state'));
    }

    public function testHtmlRobotsAndRedirectsRemainUnknownAndOfflineMakesNoRequests(): void
    {
        $calls = 0;
        $transport = $this->transport(static function (string $method, string $url) use (&$calls): MockResponse {
            ++$calls;
            return new MockResponse('<html>Not robots</html>', ['http_code' => str_ends_with($url, '/llms.txt') ? 302 : 200, 'response_headers' => ['content-type: text/html', 'location: https://other.example.com']]);
        });
        $service = new ProbeService($this->db, $this->domains, $transport, $this->snapshots, new CatalogueProvider('test'));
        $service->run(self::DOMAIN);
        $snapshot = $this->snapshots->load(self::DOMAIN)['snapshot'];
        self::assertSame('unknown', $snapshot['robots']['state']);
        self::assertSame('unknown', $snapshot['robots']['rules'][0]['decision']);
        $resources = array_column($snapshot['resources'], null, 'key');
        self::assertSame('unexpected_content_type', $resources['robots']['reasonCode']);
        self::assertSame('redirect_not_followed', $resources['llms']['reasonCode']);
        self::assertSame(8, $calls);
        $this->offline = true;
        self::assertSame('offline', $transport->request(self::URL . '/robots.txt', 'GET', 'GPTBot', true, microtime(true) + 2)['reasonCode']);
        try {
            $service->run(self::DOMAIN);
            self::fail('Offline must reject explicit probes.');
        } catch (\RuntimeException $error) {
            self::assertSame('offline', $error->getMessage());
        }
        $read = (new HealthReader($this->db, $this->domains, $this->snapshots, $transport))->read(new ReportFilter());
        self::assertTrue($read['offline']);
        self::assertNotNull($read['snapshot']);
        self::assertSame(8, $calls, 'Reading saved results never runs network requests.');
    }

    public function testTransportRejectsPrivateNetworksOversizedResponsesAndExpiredBudget(): void
    {
        $transport = $this->transport(static fn (): MockResponse => new MockResponse(str_repeat('a', RobotsPolicy::MAX_BYTES + 1), ['http_code' => 200, 'response_headers' => ['content-type: text/plain']]));
        self::assertSame('response_too_large', $transport->request(self::URL . '/robots.txt', 'GET', 'GPTBot', true, microtime(true) + 3)['reasonCode']);
        $blocked = $this->transport(static function (string $method, string $url, array $options): MockResponse {
            $options['on_progress'](0, 0, ['primary_ip' => '127.0.0.1', 'url' => $url]);
            return new MockResponse('never');
        });
        self::assertSame('network_unreachable', $blocked->request(self::URL . '/robots.txt', 'GET', 'GPTBot', true, microtime(true) + 3)['reasonCode']);
        self::assertSame('budget_exhausted', $blocked->request(self::URL . '/robots.txt', 'GET', 'GPTBot', true, microtime(true) - 1)['reasonCode']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM agentenblick_state'));
    }

    public function testContradictionsRetainOnlyPublicRulesAndNeverVisitorUrls(): void
    {
        $this->policy("User-agent: GPTBot\nAllow: /\nDisallow: /blocked\nDisallow: /query?denied=1\n");
        $observer = new Observation($this->db, $this->domains, $this->snapshots, new CatalogueProvider('test'));
        $observer->record(Request::create(self::URL . '/open?secret=DO_NOT_STORE'), $this->row(403));
        $observer->record(Request::create(self::URL . '/blocked?secret=DO_NOT_STORE'), $this->row(200));
        $observer->record(Request::create(self::URL . '/query?denied=1&secret=DO_NOT_STORE'), $this->row(200));
        $observer->record(Request::create(self::URL . '/.well-known/ucp?secret=DO_NOT_STORE'), $this->row(500));
        $observer->record(Request::create(self::URL . '/mcp/session?secret=DO_NOT_STORE'), $this->row(429));
        $observer->record(Request::create(self::URL . '/blocked?secret=DO_NOT_STORE'), array_replace($this->row(200), ['kind' => 'human_or_unknown']));
        $rows = $this->db->fetchAllAssociative('SELECT * FROM agentenblick_health_daily');
        self::assertSame(6, array_sum(array_column($rows, 'requests')));
        $codes = array_values(array_unique(array_column($rows, 'code')));
        sort($codes);
        self::assertSame(['allowed_but_blocked', 'disallowed_but_observed', 'mcp_error', 'ucp_error'], $codes);
        foreach ($rows as $row) {
            unset($row['domain_id']);
            $json = json_encode($row, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('DO_NOT_STORE', $json);
            self::assertStringNotContainsString('example.com', $json);
            self::assertSame('verified_ip', $row['evidence']);
        }
        $this->db->executeStatement('UPDATE agentenblick_health_snapshot SET checked_at = ?', [gmdate('Y-m-d H:i:s', time() - 86401)]);
        $before = (int) $this->db->fetchOne('SELECT SUM(requests) FROM agentenblick_health_daily');
        $observer->record(Request::create(self::URL . '/blocked'), $this->row(200));
        self::assertSame($before, (int) $this->db->fetchOne('SELECT SUM(requests) FROM agentenblick_health_daily'));
        $observer->record(Request::create(self::URL . '/.well-known/ucp'), $this->row(500));
        self::assertSame($before + 1, (int) $this->db->fetchOne('SELECT SUM(requests) FROM agentenblick_health_daily'));
    }

    public function testFuturePoliciesAndAmbiguousDomainsNeverCreateContradictions(): void
    {
        $this->policy("User-agent: GPTBot\nDisallow: /\n");
        $observer = new Observation($this->db, $this->domains, $this->snapshots, new CatalogueProvider('test'));
        $observer->record(Request::create(self::URL . '/private'), array_replace($this->row(200), ['observed_at' => gmdate('Y-m-d H:i:s', time() - 5)]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM agentenblick_health_daily'));
        $this->db->insert('sales_channel_domain', ['id' => random_bytes(16), 'sales_channel_id' => hex2bin(self::CHANNEL), 'url' => self::URL]);
        $observer->record(Request::create(self::URL . '/private'), $this->row(200));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM agentenblick_health_daily'));
    }

    public function testAfterLlmsRequiresSuccessfulCompletionAndKeepsStagesIndependent(): void
    {
        $session = random_bytes(16);
        $day = gmdate('Y-m-d');
        $this->requestRow($session, $day . ' 10:00:00', 'llms', 'discover', 200, $day . ' 10:00:02');
        $this->requestRow($session, $day . ' 09:59:59', '', 'search');
        $this->requestRow($session, $day . ' 10:00:01', '', 'cart'); // concurrent, response not completed
        $this->requestRow($session, $day . ' 10:00:03', '', 'product');
        $this->requestRow($session, $day . ' 10:00:04', '', 'product');
        $this->requestRow($session, gmdate('Y-m-d', time() + 86400) . ' 10:00:03', '', 'browse');
        $failed = random_bytes(16);
        $this->requestRow($failed, $day . ' 11:00:00', 'llms', 'discover', 404);
        $this->requestRow($failed, $day . ' 11:00:03', '', 'order');
        $other = random_bytes(16);
        $this->requestRow($other, $day . ' 12:00:00', 'llms', 'discover', 200, $day . ' 12:00:02');
        $this->requestRow($other, $day . ' 12:00:03', '', 'checkout', 200, null, random_bytes(16));
        $transport = $this->transport(static function (): never {
            throw new \LogicException('GET must never call HTTP.');
        });
        $after = (new HealthReader($this->db, $this->domains, $this->snapshots, $transport))->read(new ReportFilter())['afterLlms'];
        self::assertSame(2, $after['sessionsReadLlms']);
        self::assertSame(1, $after['sessionsWithLaterRequests']);
        $steps = array_column($after['steps'], 'sessions', 'stage');
        self::assertSame(1, $steps['product']);
        foreach (['search', 'cart', 'checkout', 'order', 'discover', 'browse'] as $stage) {
            self::assertSame(0, $steps[$stage], $stage);
        }
    }

    public function testConfiguredSubpathsAreRecognizedWithoutPrefixLookalikes(): void
    {
        $this->db->executeStatement('UPDATE sales_channel_domain SET url = ?', [self::URL . '/de']);
        $observer = new Observation($this->db, $this->domains, $this->snapshots, new CatalogueProvider('test'));
        foreach (['/de/llms.txt' => 'llms', '/de/store-api/_mcp' => 'mcp', '/deceptive/llms.txt' => '', '/llms.txt' => ''] as $path => $expected) {
            $request = Request::create(self::URL . $path . '?secret=discarded');
            $request->attributes->set('sw-sales-channel-id', self::CHANNEL);
            $observer->prepare($request);
            self::assertSame($expected, Observation::resource($request), $path);
            self::assertSame($expected === '' ? null : self::DOMAIN, $request->attributes->get('sw-domain-id'));
        }
        $observer->record(Request::create(self::URL . '/de/store-api/_mcp'), $this->row(500));
        $observer->record(Request::create(self::URL . '/de/.well-known/ucp'), $this->row(500));
        $observer->record(Request::create(self::URL . '/deceptive/store-api/_mcp'), $this->row(500));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT SUM(requests) FROM agentenblick_health_daily'));
    }

    public function testDomainChangesDoNotReuseTheOldHostsSnapshot(): void
    {
        $this->policy("User-agent: GPTBot\nAllow: /\n");
        $transport = $this->transport(static function (): never {
            throw new \LogicException('GET must never call HTTP.');
        });
        $reader = new HealthReader($this->db, $this->domains, $this->snapshots, $transport);
        self::assertNotNull($reader->read(new ReportFilter())['snapshot']);
        $this->db->executeStatement('UPDATE sales_channel_domain SET url = ?', ['https://changed.example.com']);
        self::assertNull($reader->read(new ReportFilter())['snapshot']);
    }

    public function testLocalDomainsStayVisibleButCannotBeProbed(): void
    {
        $this->db->executeStatement('UPDATE sales_channel_domain SET url = ?', ['http://localhost:8080']);
        self::assertFalse($this->domains->all()[0]['eligible']);
        self::assertSame('public_https_required', $this->domains->all()[0]['reasonCode']);
        $this->expectException(NotFoundHttpException::class);
        $this->domains->get(self::DOMAIN);
    }

    private function transport(callable $callback): ProbeTransport
    {
        return new ProbeTransport($this->tokens, $this->config, new MockHttpClient($callback));
    }

    private function policy(string $rules): void
    {
        $this->snapshots->save($this->domains->get(self::DOMAIN), ['checkedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'resources' => [], 'robots' => ['state' => 'parsed', 'rules' => []], 'autoTests' => []], ['mode' => 'parsed', 'groups' => (new RobotsPolicy($rules))->export(), 'domainUrl' => self::URL]);
    }

    /** @return array<string, mixed> */
    private function row(int $status): array
    {
        return ['observed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'), 'day' => gmdate('Y-m-d'), 'sales_channel_id' => self::CHANNEL, 'kind' => 'crawler', 'agent' => 'GPTBot', 'evidence' => 'verified_ip', 'route' => 'frontend.navigation.page', 'status' => $status];
    }

    private function requestRow(string $session, string $at, string $resource, string $stage, int $status = 200, ?string $completed = null, ?string $domain = null): void
    {
        $this->db->insert('agentenblick_request', ['id' => random_bytes(16), 'session_id' => $session, 'observed_at' => $at, 'sales_channel_id' => self::CHANNEL, 'domain_id' => $domain ?? hex2bin(self::DOMAIN), 'route' => 'frontend.navigation.page', 'method' => 'GET', 'status' => $status, 'operator' => 'openai', 'agent' => 'GPTBot', 'kind' => 'crawler', 'evidence' => 'verified_ip', 'reason' => 'ip_range', 'ip_hmac' => str_repeat('a', 64), 'user_agent' => 'GPTBot', 'stage' => $stage, 'discovery_resource' => $resource, 'discovery_completed_at' => $completed]);
    }
}

final class HealthFixtureConnection extends Connection
{
    /** @var array<string, string> */
    public array $tables = [];

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        return parent::executeQuery($this->mapped($sql), $params, $types, $qcp);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return parent::executeStatement($this->mapped($sql), $params, $types);
    }

    private function mapped(string $sql): string
    {
        foreach ($this->tables as $source => $target) {
            $sql = preg_replace('/\b' . preg_quote($source, '/') . '\b/', $target, $sql) ?? throw new \LogicException('Invalid fixture mapping.');
        }
        return $sql;
    }
}
