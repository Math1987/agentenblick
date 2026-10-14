<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Heuristics;

/** Bounded request facts, never headers, URLs, tokens or a human request history. */
final class History
{
    /** @param array<string, mixed> $previous
     * @return array{requests: int, intervals: int, fast_intervals: int, regular_intervals: int, cookie_misses: int, flags: int, last_ms: int, last_bin: int, cookie_offered: bool}
     */
    public static function advance(array $previous, int $flags, bool $cookiePresent, bool $cookieOffered, int $at): array
    {
        $state = [
            'requests' => self::counter($previous, 'requests'),
            'intervals' => self::counter($previous, 'intervals'),
            'fast_intervals' => self::counter($previous, 'fast_intervals'),
            'regular_intervals' => self::counter($previous, 'regular_intervals'),
            'cookie_misses' => self::counter($previous, 'cookie_misses'),
        ];
        $state['flags'] = ((int) ($previous['flags'] ?? 0) | $flags) & 4095;
        $state['last_ms'] = max(0, (int) ($previous['last_ms'] ?? 0));
        $state['last_bin'] = max(-1, min(40, (int) ($previous['last_bin'] ?? -1)));
        $state['cookie_offered'] = ($previous['cookie_offered'] ?? false) === true;
        $state['requests'] = min(255, $state['requests'] + 1);
        if ($at >= $state['last_ms']) {
            if ($state['last_ms'] > 0) {
                $delta = $at - $state['last_ms'];
                // Decay numerator and denominator together; saturating them
                // independently could turn mostly slow traffic into a fast ratio.
                if ($state['intervals'] >= 254) {
                    $state['intervals'] = intdiv($state['intervals'], 2);
                    $state['fast_intervals'] = intdiv($state['fast_intervals'], 2);
                }
                ++$state['intervals'];
                $state['fast_intervals'] = min(255, $state['fast_intervals'] + ($delta <= 250 ? 1 : 0));
                $bin = $delta <= 10000 ? (int) floor($delta / 250) : -1;
                $state['regular_intervals'] = $bin >= 0 ? ($bin === $state['last_bin'] ? min(255, $state['regular_intervals'] + 1) : 1) : 0;
                $state['last_bin'] = $bin;
                if ($state['cookie_offered'] && !$cookiePresent) {
                    $state['cookie_misses'] = min(255, $state['cookie_misses'] + 1);
                }
            }
            $state['last_ms'] = $at;
            // A missing later Set-Cookie does not retract an unacknowledged offer.
            $state['cookie_offered'] = $cookieOffered || ($state['cookie_offered'] && !$cookiePresent);
        }
        return $state;
    }

    /** @param array<string, mixed> $previous */
    private static function counter(array $previous, string $name): int
    {
        return max(0, min(255, (int) ($previous[$name] ?? 0)));
    }
}
