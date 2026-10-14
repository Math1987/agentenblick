<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit\Benchmark;

use Aithos\Agentenblick\Benchmark\JourneyPayload;
use PHPUnit\Framework\TestCase;

final class JourneyPayloadTest extends TestCase
{
    public function testFailureDenominatorsUseOnlyEvaluatedSessionsAndProofGroupsRemainSeparate(): void
    {
        $rows = [];
        foreach (['sessions' => 20, 'step.search' => 15, 'step.order' => 2, 'known.search_empty' => 9, 'failure.search_empty' => 3, 'known.http_4xx' => 20, 'failure.http_4xx' => 1] as $metric => $count) {
            $rows[] = ['evidence' => 'verified_ip', 'metric' => $metric, 'sessions' => $count];
        }
        $rows[] = ['evidence' => 'declared_unverified', 'metric' => 'sessions', 'sessions' => 9];
        $result = JourneyPayload::reduce($rows);
        self::assertSame(['denominator' => 'at_least_10', 'pct' => 75.0], $result['funnel']['verified']['search']);
        self::assertSame(10.0, $result['funnel']['verified']['order']['pct']);
        self::assertSame('suppressed', $result['funnel']['declared']['order']['pct']);
        self::assertSame('suppressed', $result['failures']['verified']['search_empty']['pct']);
        self::assertSame(5.0, $result['failures']['verified']['http_4xx']['pct']);
    }

    public function testImpossibleCountsCannotBecomePlausibleClampedPercentages(): void
    {
        $this->expectException(\RuntimeException::class);
        JourneyPayload::reduce([
            ['evidence' => 'verified_ip', 'metric' => 'sessions', 'sessions' => 10],
            ['evidence' => 'verified_ip', 'metric' => 'known.search_empty', 'sessions' => 11],
        ]);
    }
}
