<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Integration;

use Aithos\Agentenblick\Admin\AnalyticsReader;
use Aithos\Agentenblick\Admin\RangeApprovals;
use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Command\ReportCommand;
use Aithos\Agentenblick\Controller\AnalyticsController;
use Aithos\Agentenblick\Persistence\ProxyDiagnostics;
use Aithos\Agentenblick\Verification\CatalogueProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Temporary tables isolate every test from the live disposable-bench data. */
final class AdminAnalyticsTest extends TestCase
{
    private const CHANNEL = 'fffffffffffffffffffffffffffffffb';
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
            self::markTestSkipped('Requires the disposable MySQL bench.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware'));
        foreach (['daily', 'request', 'state', 'ip_ranges'] as $name) {
            $definition = $this->db()->fetchAssociative('SHOW CREATE TABLE agentenblick_' . $name);
            self::assertNotFalse($definition);
            $this->db()->executeStatement('CREATE TEMPORARY TABLE ' . substr($definition['Create Table'], strlen('CREATE TABLE ')));
        }
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testAdminCliAndDailyTrendAgreeWithFilteredCounters(): void
    {
        $this->seed();
        $reader = $this->reader();
        $summary = $reader->summary(new ReportFilter(7, self::CHANNEL));
        self::assertSame(['requests' => 16, 'sessions' => 8, 'agentRequests' => 6, 'agentSessions' => 3, 'suspectedRequests' => 0, 'suspectedSessions' => 0, 'agentRequestsPct' => 37.5, 'agentSessionsPct' => 37.5], $summary['totals']);
        self::assertCount(7, $summary['daily']);
        self::assertSame(16, array_sum(array_column($summary['daily'], 'requests')));
        self::assertSame(6, array_sum(array_column($summary['byKind'], 'requests')));
        self::assertSame(3, array_sum(array_column($summary['byEvidence'], 'sessions')));
        $command = new CommandTester(new ReportCommand($reader, new ProxyDiagnostics($this->db())));
        self::assertSame(0, $command->execute(['--json' => true, '--sales-channel' => self::CHANNEL]));
        $cli = json_decode($command->getDisplay(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($summary['totals']['sessions'], $cli['sessions']);
        self::assertSame($summary['totals']['agentSessions'], $cli['observed_agent_sessions']);
        self::assertSame(16, array_sum(array_column($cli['agents'], 'requests')));
    }

    public function testSuspicionStaysSeparateInTotalsTrendsAndCli(): void
    {
        $this->seed();
        $this->db()->insert('agentenblick_daily', ['day' => gmdate('Y-m-d'), 'sales_channel_id' => self::CHANNEL, 'operator' => 'unknown', 'agent' => 'unknown', 'kind' => 'automation_unknown', 'evidence' => 'suspected', 'requests' => 4, 'sessions' => 2]);
        $summary = $this->reader()->summary(new ReportFilter(7, self::CHANNEL));
        self::assertSame(10, $summary['totals']['sessions']);
        self::assertSame(3, $summary['totals']['agentSessions']);
        self::assertSame(2, $summary['totals']['suspectedSessions']);
        self::assertSame(30.0, $summary['totals']['agentSessionsPct']);
        self::assertSame(4, array_sum(array_column($summary['daily'], 'suspectedRequests')));
        self::assertSame(3, array_sum(array_column($summary['byKind'], 'sessions')));
        self::assertSame(5, array_sum(array_column($summary['byEvidence'], 'sessions')));
        $command = new CommandTester(new ReportCommand($this->reader(), new ProxyDiagnostics($this->db())));
        self::assertSame(0, $command->execute(['--json' => true, '--sales-channel' => self::CHANNEL]));
        $cli = json_decode($command->getDisplay(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(3, $cli['observed_agent_sessions']);
        self::assertSame(2, $cli['suspected_automation_sessions']);
    }

    public function testAgentsCombineProofsAndOnlyReturnMinimizedAgentRoutes(): void
    {
        $this->seed();
        $agents = $this->reader()->agents(new ReportFilter(7, self::CHANNEL, 1, 1));
        self::assertSame(1, $agents['total']);
        self::assertCount(1, $agents['items']);
        $agent = $agents['items'][0];
        self::assertSame('GPTBot', $agent['agent']);
        self::assertSame('mixed', $agent['evidence']);
        self::assertSame(6, $agent['requests']);
        self::assertSame(3, $agent['sessions']);
        self::assertCount(2, $agent['byEvidence']);
        self::assertSame([['route' => 'frontend.detail.page', 'requests' => 2], ['route' => 'frontend.navigation.page', 'requests' => 1]], $agent['topRoutes']);
        self::assertStringEndsWith('Z', $agent['lastSeen']);
        self::assertStringNotContainsString('private-test-marker', json_encode($agents, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->reader()->agents(new ReportFilter(7, self::CHANNEL, 2, 1))['items']);
    }

    public function testControllerReturnsNoStoreAndAuthenticatedCsvDataShape(): void
    {
        $this->seed();
        $controller = new AnalyticsController($this->reader(), $this->approvals());
        $request = new Request(['days' => '7', 'salesChannelId' => self::CHANNEL]);
        $response = $controller->summary($request, Context::createDefaultContext());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        $csv = $controller->export($request);
        self::assertSame('text/csv; charset=utf-8', $csv->headers->get('Content-Type'));
        self::assertStringContainsString('GPTBot', (string) $csv->getContent());
        self::assertStringNotContainsString('private-test-marker', (string) $csv->getContent());
    }

    public function testRangeApprovalRequiresTheExactReviewedProposal(): void
    {
        $this->db()->insert('agentenblick_ip_ranges', ['agent' => 'GPTBot', 'ranges_json' => '["8.8.8.0/24"]', 'pending_json' => '["8.8.4.0/24"]', 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $approvals = $this->approvals();
        $proposal = $approvals->pending()[0];
        self::assertSame(['8.8.4.0/24'], $proposal['addedRanges']);
        self::assertSame(['8.8.8.0/24'], $proposal['removedRanges']);
        $this->db()->update('agentenblick_ip_ranges', ['pending_json' => '["1.1.1.0/24"]'], ['agent' => 'GPTBot']);
        try {
            $approvals->approve('GPTBot', $proposal['version']);
            self::fail('A stale approval must fail.');
        } catch (ConflictHttpException) {
            self::assertSame('["8.8.8.0/24"]', $this->db()->fetchOne('SELECT ranges_json FROM agentenblick_ip_ranges'));
        }
        $current = $approvals->pending()[0];
        $approvals->approve('GPTBot', $current['version']);
        self::assertSame(['1.1.1.0/24'], json_decode($this->db()->fetchOne('SELECT ranges_json FROM agentenblick_ip_ranges'), true, 16, JSON_THROW_ON_ERROR));
        self::assertNull($this->db()->fetchOne('SELECT pending_json FROM agentenblick_ip_ranges'));
    }

    private function seed(): void
    {
        $today = gmdate('Y-m-d');
        foreach ([['unknown', 'unknown', 'human_or_unknown', 'none', 10, 5], ['openai', 'GPTBot', 'training_crawler', 'verified_ip', 4, 2], ['openai', 'GPTBot', 'training_crawler', 'declared_unverified', 2, 1]] as [$operator, $agent, $kind, $evidence, $requests, $sessions]) {
            $this->db()->insert('agentenblick_daily', ['day' => $today, 'sales_channel_id' => self::CHANNEL, 'operator' => $operator, 'agent' => $agent, 'kind' => $kind, 'evidence' => $evidence, 'browser_family' => $kind === 'human_or_unknown' ? 'Chrome' : '', 'requests' => $requests, 'sessions' => $sessions]);
        }
        foreach ([[$today, str_repeat('e', 32)], [gmdate('Y-m-d', time() - 86400 * 8), self::CHANNEL], [gmdate('Y-m-d', time() + 86400), self::CHANNEL]] as [$day, $channel]) {
            $this->db()->insert('agentenblick_daily', ['day' => $day, 'sales_channel_id' => $channel, 'operator' => 'other', 'agent' => 'excluded', 'kind' => 'browsing_agent', 'evidence' => 'declared_unverified', 'requests' => 99, 'sessions' => 99]);
        }
        foreach (['frontend.detail.page', 'frontend.detail.page', 'frontend.navigation.page'] as $route) {
            $this->db()->insert('agentenblick_request', ['id' => random_bytes(16), 'session_id' => random_bytes(16), 'observed_at' => $today . ' 12:00:00', 'sales_channel_id' => self::CHANNEL, 'route' => $route, 'method' => 'GET', 'status' => 200, 'operator' => 'openai', 'agent' => 'GPTBot', 'kind' => 'training_crawler', 'evidence' => 'verified_ip', 'reason' => 'fixture', 'ip_hmac' => str_repeat('a', 64), 'user_agent' => 'private-test-marker']);
        }
    }

    private function reader(): AnalyticsReader
    {
        return new AnalyticsReader($this->db(), new ProxyDiagnostics($this->db()));
    }
    private function approvals(): RangeApprovals
    {
        return new RangeApprovals($this->db(), new CatalogueProvider('test'));
    }
    private function db(): Connection
    {
        return $this->connection ?? throw new \LogicException('No test database.');
    }
}
