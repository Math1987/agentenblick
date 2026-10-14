<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Journey;

use Doctrine\DBAL\Connection;

/** Each closed agent session contributes at most one to each named counter. */
final class JourneyContribution
{
    /** @param array<string, mixed> $session
     * @return array{dimensions: list<string>, metrics: list<string>}|null
     */
    public static function snapshot(array $session): ?array
    {
        if ($session['kind'] === 'human_or_unknown' || ($session['closed_at'] ?? null) === null) {
            return null;
        }
        $steps = (int) ($session['steps_mask'] ?? 0);
        $failures = (int) ($session['failures_mask'] ?? 0);
        $known = (int) ($session['known_mask'] ?? 0);
        // Historical sessions predating journey instrumentation must not become
        // apparently successful/zero-step outcomes after an upgrade.
        if ($steps === 0 && $known === 0) {
            return null;
        }
        $metrics = ['sessions'];
        foreach (Stage::cases() as $stage) {
            if (($steps & $stage->bit()) !== 0) {
                $metrics[] = 'step.' . $stage->value;
            }
        }
        foreach (Failure::cases() as $failure) {
            $code = $failure->code();
            $eligible = self::eligible($code, $steps, $known);
            $failed = ($failures & $failure->value) !== 0;
            if ($code === 'abandoned_cart') {
                $failed = $eligible && ($steps & 32) === 0 && ($steps & 64) === 0;
            } elseif ($code === 'abandoned_checkout') {
                $failed = $eligible && ($steps & 64) === 0;
            }
            if ($eligible) {
                $metrics[] = 'known.' . $code;
                if ($failed) {
                    $metrics[] = 'failure.' . $code;
                }
            }
        }
        sort($metrics);
        return ['dimensions' => [substr((string) $session['first_seen'], 0, 10), (string) $session['sales_channel_id'], (string) $session['operator'], (string) $session['agent'], (string) $session['kind'], (string) $session['evidence']], 'metrics' => $metrics];
    }

    public static function eligible(string $failure, int $steps, int $known): bool
    {
        return match ($failure) {
            'search_empty' => ($known & Signals::KNOWN_SEARCH) !== 0,
            'product_unavailable', 'product_out_of_stock' => ($known & Signals::KNOWN_PRODUCT) !== 0,
            'cart_error' => ($known & Signals::KNOWN_CART) !== 0,
            'checkout_form' => ($known & Signals::KNOWN_CHECKOUT) !== 0,
            'payment_error' => ($known & Signals::KNOWN_PAYMENT) !== 0,
            'http_4xx', 'http_5xx', 'forbidden', 'rate_limited' => ($known & Signals::KNOWN_HTTP) !== 0,
            'abandoned_cart' => ($steps & 16) !== 0,
            'abandoned_checkout' => ($steps & 32) !== 0,
            default => false,
        };
    }

    /** Caller owns the session row lock and transaction.
     * @param array<string, mixed> $session Current row, including the previous saved contribution.
     */
    public static function reconcile(Connection $connection, array $session): void
    {
        $old = is_string($session['contribution'] ?? null) ? json_decode($session['contribution'], true, 8, JSON_THROW_ON_ERROR) : null;
        $next = self::snapshot($session);
        if ($old === $next) {
            return;
        }
        if (is_array($old)) {
            foreach ($old['metrics'] as $metric) {
                $changed = $connection->executeStatement('UPDATE agentenblick_journey_daily SET sessions = sessions - 1 WHERE day = ? AND sales_channel_id = ? AND operator = ? AND agent = ? AND kind = ? AND evidence = ? AND metric = ? AND sessions > 0', [...$old['dimensions'], $metric]);
                if ($changed !== 1) {
                    throw new \LogicException('missing_journey_contribution');
                }
            }
        }
        if ($next !== null) {
            foreach ($next['metrics'] as $metric) {
                $connection->executeStatement('INSERT INTO agentenblick_journey_daily (day, sales_channel_id, operator, agent, kind, evidence, metric, sessions) VALUES (?, ?, ?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE sessions = sessions + 1', [...$next['dimensions'], $metric]);
            }
        }
        $connection->update('agentenblick_session', ['contribution' => $next === null ? null : json_encode($next, JSON_THROW_ON_ERROR)], ['id' => $session['id']]);
    }
}
