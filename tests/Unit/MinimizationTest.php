<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Capture\Minimizer;
use Aithos\Agentenblick\Capture\ObservationScope;
use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Verification\Catalogue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class MinimizationTest extends TestCase
{
    public function testSensitiveInputsDoNotSurviveMinimization(): void
    {
        $request = Request::create('https://shop.test/account/person@example.test?token=private-query', 'POST', [], ['session-' => 'private-cookie'], [], ['REMOTE_ADDR' => '203.0.113.42'], 'private-body');
        $request->headers->add(['User-Agent' => 'GPTBot/1.0', 'sw-context-token' => 'private-context']);
        $row = (new Minimizer())->minimize($request, new Classification('openai', 'GPTBot', Kind::TrainingCrawler, Evidence::Declared), random_bytes(32), new \DateTimeImmutable('2026-10-14T10:00:00Z'), 200);
        $json = json_encode($row, JSON_THROW_ON_ERROR);
        foreach (['203.0.113.42', 'private-query', 'private-cookie', 'private-context', 'private-body', 'person@example.test'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $json);
        }
        self::assertSame('/{unresolved}', $row['route']);
        self::assertNull($row['network_prefix']);
        self::assertSame(64, strlen($row['ip_hmac']));
    }

    public function testHumansKeepNoRawUserAgentAndDailyKeysBreakLinkability(): void
    {
        $request = Request::create('https://shop.test/', server: ['REMOTE_ADDR' => '203.0.113.42', 'HTTP_USER_AGENT' => 'PersonalBrowser/1.2.3']);
        $minimizer = new Minimizer();
        $a = $minimizer->minimize($request, new Classification(), str_repeat('a', 32), new \DateTimeImmutable('2026-10-14T10:00:00Z'), 200);
        $b = $minimizer->minimize($request, new Classification(), str_repeat('b', 32), new \DateTimeImmutable('2026-10-15T10:00:00Z'), 200);
        self::assertSame('', $a['user_agent']);
        self::assertNotSame($a['ip_hmac'], $b['ip_hmac']);
        self::assertNotSame($a['session_key'], $b['session_key']);
    }

    public function testAdminAndAssetsAreExcludedButDiscoveryAndStoreApiAreIncluded(): void
    {
        $scope = new ObservationScope();
        foreach (['/admin', '/api/search/product', '/_profiler/a', '/theme/a.css'] as $path) {
            self::assertFalse($scope->accepts(Request::create($path)));
        }
        foreach (['/store-api/search', '/ucp/v1/carts', '/robots.txt', '/.well-known/ucp'] as $path) {
            self::assertTrue($scope->accepts(Request::create($path)));
        }
    }

    public function testProductionRefusesTestCatalogue(): void
    {
        $this->expectException(\LogicException::class);
        new Catalogue(__DIR__ . '/../fixtures/catalog.json', 'prod');
    }
}
