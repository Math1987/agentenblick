<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Benchmark;

final class JourneyPayload
{
    public const STAGES = ['discover', 'browse', 'search', 'product', 'cart', 'checkout', 'order'];
    public const FAILURES = ['search_empty', 'product_unavailable', 'product_out_of_stock', 'http_4xx', 'http_5xx', 'forbidden', 'rate_limited', 'checkout_form', 'cart_error', 'payment_error', 'abandoned_cart', 'abandoned_checkout'];

    /** @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function reduce(array $rows): array
    {
        $counts = ['verified' => [], 'declared' => []];
        foreach ($rows as $row) {
            $proof = (string) ($row['evidence'] ?? '');
            $group = in_array($proof, ['verified_signature', 'verified_ip', 'verified_rdns'], true) ? 'verified' : ($proof === 'declared_unverified' ? 'declared' : null);
            if ($group === null) {
                continue;
            }
            $metric = (string) ($row['metric'] ?? '');
            $counts[$group][$metric] = ($counts[$group][$metric] ?? 0) + max(0, (int) ($row['sessions'] ?? 0));
        }
        $result = ['funnel' => [], 'failures' => []];
        foreach ($counts as $group => $metrics) {
            foreach (self::STAGES as $stage) {
                $result['funnel'][$group][$stage] = self::metric($metrics['step.' . $stage] ?? 0, $metrics['sessions'] ?? 0);
            }
            foreach (self::FAILURES as $failure) {
                if (($metrics['known.' . $failure] ?? 0) > ($metrics['sessions'] ?? 0)) {
                    throw new \RuntimeException('benchmark_journey_inconsistent');
                }
                $result['failures'][$group][$failure] = self::metric($metrics['failure.' . $failure] ?? 0, $metrics['known.' . $failure] ?? 0);
            }
        }
        return $result;
    }

    /** @return array{denominator: string, pct: float|string} */
    private static function metric(int $numerator, int $denominator): array
    {
        if ($numerator > $denominator) {
            throw new \RuntimeException('benchmark_journey_inconsistent');
        }
        return ['denominator' => $denominator < 10 ? 'below_10' : 'at_least_10', 'pct' => $denominator < 10 ? 'suppressed' : round(100 * $numerator / $denominator, 1)];
    }
}
