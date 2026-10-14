<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Heuristics;

/** Versioned, explainable experimental score, frozen before held-out evaluation. */
final class HeuristicScore
{
    public const VERSION = '1';
    public const HIGH_CADENCE = 512;
    public const REGULAR_CADENCE = 1024;
    public const COOKIE_IGNORED = 2048;
    public const MASK = 4095;
    public const WEIGHTS = [1 => 5, 2 => 3, 4 => 20, 8 => 45, 16 => 12, 32 => 8, 64 => 7, 128 => 10, 256 => 5, 512 => 25, 1024 => 25, 2048 => 25];
    private const CODES = [1 => 'fetch_metadata_missing', 2 => 'client_hints_missing', 4 => 'browser_headers_incoherent', 8 => 'tool_user_agent', 16 => 'wildcard_html_accept', 32 => 'language_missing', 64 => 'deep_without_referer', 128 => 'api_without_session_cookie', 256 => 'hosting_network', 512 => 'high_cadence', 1024 => 'regular_cadence', 2048 => 'cookie_ignored'];

    /**
     * Counters are accumulated by the persistence layer under its session mutex.
     * cookie_misses counts only requests after a real session cookie was offered.
     * regular_intervals counts a consecutive run in the same 250 ms gap bucket,
     * for gaps of at most 10 seconds; fast_intervals counts gaps <= 250 ms.
     *
     * @param array{requests?: int, intervals?: int, fast_intervals?: int, regular_intervals?: int, cookie_misses?: int} $counters
     * @return array{score: int, flags: int, version: string, suspected: bool}
     */
    public function evaluate(int $flags, array $counters, int $threshold = 60): array
    {
        $flags &= HeuristicSignals::REQUEST_MASK;
        $requests = self::bounded($counters['requests'] ?? 0);
        $intervals = min(max(0, $requests - 1), self::bounded($counters['intervals'] ?? 0));
        $fast = min($intervals, self::bounded($counters['fast_intervals'] ?? 0));
        $regular = min($intervals, self::bounded($counters['regular_intervals'] ?? 0));
        $misses = min($requests, self::bounded($counters['cookie_misses'] ?? 0));
        if ($requests >= 6 && $fast >= 5 && $fast * 10 >= $intervals * 7) {
            $flags |= self::HIGH_CADENCE;
        }
        if ($requests >= 8 && $regular >= 7) {
            $flags |= self::REGULAR_CADENCE;
        }
        if ($requests >= 4 && $misses >= 3) {
            $flags |= self::COOKIE_IGNORED;
        }
        $score = 0;
        foreach (self::WEIGHTS as $bit => $weight) {
            if (($flags & $bit) !== 0) {
                $score += $weight;
            }
        }
        $score = min(100, $score);
        return ['score' => $score, 'flags' => $flags, 'version' => self::VERSION, 'suspected' => $score >= max(30, min(100, $threshold))];
    }

    /** @return list<string> */
    public static function codes(int $flags): array
    {
        $codes = [];
        foreach (self::CODES as $bit => $code) {
            if (($flags & $bit) !== 0) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    private static function bounded(int $value): int
    {
        return max(0, min(255, $value));
    }
}
