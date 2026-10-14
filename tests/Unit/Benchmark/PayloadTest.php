<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit\Benchmark;

use Aithos\Agentenblick\Benchmark\Payload;
use Aithos\Agentenblick\Benchmark\Period;
use Aithos\Agentenblick\Benchmark\SecretVault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase
{
    /** @return iterable<string, array{int, int, float|string, bool}> */
    public static function thresholds(): iterable
    {
        yield 'zero' => [0, 0, 'suppressed', true];
        yield 'nine total' => [9, 9, 'suppressed', true];
        yield 'ten total nine agents' => [10, 9, 90.0, true];
        yield 'ten agents' => [10, 10, 100.0, false];
        yield 'zero agents above total threshold' => [10, 0, 0.0, true];
    }

    #[DataProvider('thresholds')]
    public function testIndependentDenominatorsPreventSmallSampleDisclosure(int $total, int $agents, float|string $share, bool $suppressed): void
    {
        $document = Payload::document(Payload::uuid(), Payload::uuid(), '2026-W40', true, ['country' => 'DE', 'shopware' => '6.7', 'plugin' => '0.1.0', 'trafficBucket' => '<1k', 'agenticCommerceExtension' => false], [
            ['kind' => 'human_or_unknown', 'evidence' => 'none', 'operator' => 'unknown', 'sessions' => $total - $agents],
            ['kind' => 'training_crawler', 'evidence' => 'verified_ip', 'operator' => 'openai', 'sessions' => $agents],
        ]);
        self::assertSame($share, $document['shares']['aiSessionsPct']);
        self::assertSame($total < 10 ? 'below_10' : 'at_least_10', $document['denominators']['sessions']);
        self::assertSame($agents < 10 ? 'below_10' : 'at_least_10', $document['denominators']['agentSessions']);
        foreach (['byKind', 'byEvidence', 'byOperator'] as $field) {
            if ($suppressed) {
                self::assertSame('suppressed', $document['shares'][$field]);
            } else {
                self::assertEqualsWithDelta(100.0, array_sum($document['shares'][$field]), 0.00001);
            }
        }
        self::assertSame('agentenblick.benchmark/2', $document['schema']);
        self::assertSame(['denominator' => 'below_10', 'pct' => 'suppressed'], $document['funnel']['verified']['order']);
        self::assertSame(['denominator' => 'below_10', 'pct' => 'suppressed'], $document['failures']['declared']['search_empty']);
        self::assertStringNotContainsString('"requests"', Payload::bytes($document));
        self::assertStringNotContainsString('"sessions":' . $total, Payload::bytes($document));
    }

    public function testSuspectedAutomationNeverContaminatesTheVersionTwoAgentCohort(): void
    {
        $document = Payload::document(Payload::uuid(), Payload::uuid(), '2026-W40', true, ['country' => 'DE', 'shopware' => '6.7', 'plugin' => '0.1.0', 'trafficBucket' => '<1k', 'agenticCommerceExtension' => false], [
            ['kind' => 'human_or_unknown', 'evidence' => 'none', 'sessions' => 10],
            ['kind' => 'automation_unknown', 'evidence' => 'suspected', 'sessions' => 80],
            ['kind' => 'training_crawler', 'evidence' => 'verified_ip', 'operator' => 'openai', 'sessions' => 10],
        ], [
            ['evidence' => 'suspected', 'metric' => 'sessions', 'sessions' => 80],
            ['evidence' => 'suspected', 'metric' => 'step.order', 'sessions' => 80],
        ]);
        self::assertSame(10.0, $document['shares']['aiSessionsPct']);
        self::assertSame(0.0, $document['shares']['byEvidence']['none']);
        self::assertSame(100.0, $document['shares']['byEvidence']['verified_ip']);
        self::assertSame(0.0, $document['shares']['byKind']['automation_unknown']);
        self::assertSame(['denominator' => 'below_10', 'pct' => 'suppressed'], $document['funnel']['verified']['order']);
        self::assertSame(['denominator' => 'below_10', 'pct' => 'suppressed'], $document['funnel']['declared']['order']);
        self::assertStringNotContainsString('suspected', Payload::bytes($document));
    }

    public function testRoundingKeepsExactlyOneHundredAndFixedOperatorBuckets(): void
    {
        self::assertSame(['a' => 33.4, 'b' => 33.3, 'c' => 33.3], Payload::distribution(['a' => 4, 'b' => 4, 'c' => 4]));
        self::assertSame(['<1k', '1k-10k', '10k-100k', '100k+'], array_map(Payload::bucket(...), [999, 1000, 10000, 100000]));
    }

    public function testIdentitiesAreUnrelatedRandomUuidV4AndSecretIsAuthenticatedEncrypted(): void
    {
        $installation = Payload::uuid();
        $channel = Payload::uuid();
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $installation);
        self::assertNotSame($installation, $channel);
        $vault = new SecretVault('test-kernel-secret');
        $secret = 'secret-that-must-never-appear-in-admin';
        $encrypted = $vault->encrypt($secret);
        self::assertStringNotContainsString($secret, $encrypted);
        self::assertSame($secret, $vault->decrypt($encrypted));
        $this->expectException(\RuntimeException::class);
        (new SecretVault('different-kernel-secret'))->decrypt($encrypted);
    }

    public function testIsoWeeksAreCompletedUtcIntervalsIncludingYearBoundary(): void
    {
        $now = new \DateTimeImmutable('2027-01-04T00:00:01Z');
        self::assertSame('2026-W53', Period::previous($now));
        self::assertSame(['2026-12-28', '2027-01-04'], Period::bounds('2026-W53', $now));
        $this->expectException(\InvalidArgumentException::class);
        Period::bounds('2027-W01', $now);
    }
}
