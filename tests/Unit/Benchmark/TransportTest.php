<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit\Benchmark;

use Aithos\Agentenblick\Benchmark\Comparison;
use Aithos\Agentenblick\Benchmark\Transport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TransportTest extends TestCase
{
    public function testSendsTheExactReviewedBytesWithBoundedTransportAndHmac(): void
    {
        $bytes = '{"shares":{"aiSessionsPct":10.0},"unicode":"é"}';
        $secret = 'client-secret-kept-as-utf8-not-base64-decoded';
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($bytes, $secret, $id): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://shopware.aithos.app/v1/submissions', $url);
            self::assertSame($bytes, $options['body']);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(6.0, $options['max_duration']);
            $headers = [];
            foreach ($options['headers'] as $line) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower($key)] = trim($value);
            }
            self::assertSame($id, $headers['x-agentenblick-installation']);
            self::assertSame(hash_hmac('sha256', $headers['x-agentenblick-timestamp'] . "\nPOST\n/v1/submissions\n" . $bytes, $secret), $headers['x-agentenblick-signature']);
            return new MockResponse('{"accepted":true}', ['http_code' => 201]);
        });
        self::assertSame(201, (new Transport($client))->request('POST', '/v1/submissions', $bytes, $id, $secret)['status']);
    }

    public function testResponseIsCappedAndArbitraryOriginsAreRejected(): void
    {
        $transport = new Transport(new MockHttpClient(new MockResponse(str_repeat('x', 65537))));
        $this->expectException(\RuntimeException::class);
        $transport->request('GET', '/v1/benchmarks');
    }

    public function testUntrustedRouteCannotOverrideFixedHttpsDestination(): void
    {
        $transport = new Transport(new MockHttpClient(static function (): never {
            self::fail('No network request expected.');
        }));
        $this->expectException(\InvalidArgumentException::class);
        $transport->request('GET', '//attacker.example/v1/benchmarks');
    }

    public function testComparisonDropsUndersizedCellsAndUnexpectedFields(): void
    {
        $report = Comparison::sanitize(['cells' => [['period' => '2026-W40', 'cohortId' => 'DE|6.7|<1k|0', 'rawSecret' => 'never-return-me', 'metrics' => ['aiSessionsPct' => ['p25' => 1, 'median' => 2, 'p75' => 3, 'installations' => 4], 'byKind' => ['training_crawler' => ['p25' => 1, 'median' => 2, 'p75' => 3, 'installations' => 5]]]]]], '2026-W40', 'DE|6.7|<1k|0');
        self::assertSame('suppressed', $report['cells'][0]['metrics']['aiSessionsPct']);
        self::assertSame(5, $report['cells'][0]['metrics']['byKind']['training_crawler']['installations']);
        self::assertStringNotContainsString('never-return-me', json_encode($report, JSON_THROW_ON_ERROR));
    }
}
