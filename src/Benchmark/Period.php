<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Benchmark;

final class Period
{
    public static function previous(?\DateTimeImmutable $now = null): string
    {
        return self::monday($now)->modify('-1 week')->format('o-\WW');
    }

    /** @return array{0: string, 1: string} */
    public static function bounds(string $period, ?\DateTimeImmutable $now = null): array
    {
        if (!preg_match('/^(20[2-9][0-9])-W([0-5][0-9])$/D', $period, $match)) {
            throw new \InvalidArgumentException('Invalid benchmark week.');
        }
        $start = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setISODate((int) $match[1], (int) $match[2])->setTime(0, 0);
        $current = self::monday($now);
        if ($start->format('o-\WW') !== $period || $start >= $current || $start < $current->modify('-13 weeks')) {
            throw new \InvalidArgumentException('Only completed weeks within the last 13 weeks are available.');
        }
        return [$start->format('Y-m-d'), $start->modify('+1 week')->format('Y-m-d')];
    }

    private static function monday(?\DateTimeImmutable $now): \DateTimeImmutable
    {
        $now = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        return $now->modify('-' . ((int) $now->format('N') - 1) . ' days')->setTime(0, 0);
    }
}
