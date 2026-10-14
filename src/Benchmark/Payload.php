<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Benchmark;

final class Payload
{
    public const KINDS = ['training_crawler', 'search_crawler', 'user_fetch', 'browsing_agent', 'automation_unknown'];
    public const EVIDENCE = ['verified_signature', 'verified_ip', 'verified_rdns', 'declared_unverified', 'none'];
    public const OPERATORS = ['openai', 'google', 'anthropic', 'perplexity', 'other'];

    /** @param list<array<string, mixed>> $groups
     * @param array{country: string, shopware: string, plugin: string, trafficBucket: string, agenticCommerceExtension: bool} $segment
     * @return array<string, mixed>
     */
    public static function document(string $installation, string $channel, string $period, bool $demo, array $segment, array $groups): array
    {
        $total = 0;
        $agents = 0;
        $kinds = array_fill_keys(self::KINDS, 0);
        $evidence = array_fill_keys(self::EVIDENCE, 0);
        $operators = array_fill_keys(self::OPERATORS, 0);
        foreach ($groups as $row) {
            $count = max(0, (int) ($row['sessions'] ?? 0));
            $total += $count;
            if (($row['kind'] ?? '') === 'human_or_unknown') {
                continue;
            }
            $agents += $count;
            $kind = in_array($row['kind'] ?? '', self::KINDS, true) ? $row['kind'] : 'automation_unknown';
            $proof = in_array($row['evidence'] ?? '', self::EVIDENCE, true) ? $row['evidence'] : 'none';
            $operator = in_array($row['operator'] ?? '', self::OPERATORS, true) ? $row['operator'] : 'other';
            $kinds[$kind] += $count;
            $evidence[$proof] += $count;
            $operators[$operator] += $count;
        }
        return [
            'schema' => 'agentenblick.benchmark/1', 'installationId' => $installation, 'channelId' => $channel,
            'period' => $period, 'demo' => $demo, 'segment' => $segment,
            'denominators' => ['sessions' => $total < 10 ? 'below_10' : 'at_least_10', 'agentSessions' => $agents < 10 ? 'below_10' : 'at_least_10'],
            'shares' => [
                'aiSessionsPct' => $total < 10 ? 'suppressed' : round(100 * $agents / $total, 1),
                'byKind' => $agents < 10 ? 'suppressed' : self::distribution($kinds),
                'byEvidence' => $agents < 10 ? 'suppressed' : self::distribution($evidence),
                'byOperator' => $agents < 10 ? 'suppressed' : self::distribution($operators),
            ],
            'funnel' => 'suppressed', 'failures' => 'suppressed',
        ];
    }

    public static function bucket(int $sessions): string
    {
        return match (true) {
            $sessions < 1000 => '<1k', $sessions < 10000 => '1k-10k', $sessions < 100000 => '10k-100k', default => '100k+'
        };
    }

    /** Largest remainders preserve a total of exactly 100.0 after rounding.
     * @param array<string, int> $counts
     * @return array<string, float>
     */
    public static function distribution(array $counts): array
    {
        $total = array_sum($counts);
        if ($total < 10) {
            throw new \InvalidArgumentException('Suppressed distributions cannot be computed.');
        }
        $ticks = $fractions = [];
        foreach ($counts as $key => $count) {
            $exact = 1000 * $count / $total;
            $ticks[$key] = (int) floor($exact);
            $fractions[$key] = $exact - $ticks[$key];
        }
        arsort($fractions, SORT_NUMERIC);
        $remaining = 1000 - array_sum($ticks);
        foreach ($fractions as $key => $_) {
            if ($remaining-- <= 0) {
                break;
            }
            ++$ticks[$key];
        }
        return array_map(static fn (int $value): float => $value / 10, $ticks);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /** @param array<string, mixed> $document */
    public static function bytes(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
