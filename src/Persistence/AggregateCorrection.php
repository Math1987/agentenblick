<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Persistence;

use Aithos\Agentenblick\Journey\JourneyContribution;
use Doctrine\DBAL\Connection;

/** Move a previously counted observation; never count it twice. Caller owns the transaction. */
final class AggregateCorrection
{
    /** @param array<string, mixed> $from
     * @param array<string, mixed> $to
     */
    public static function move(Connection $connection, string $counter, array $from, array $to): void
    {
        if (!in_array($counter, ['requests', 'sessions'], true)) {
            throw new \InvalidArgumentException('invalid_aggregate_counter');
        }
        $from += ['browser_family' => ''];
        $to += ['browser_family' => ''];
        $columns = ['day', 'sales_channel_id', 'operator', 'agent', 'kind', 'evidence', 'browser_family'];
        $source = array_map(static fn (string $name): mixed => $from[$name], $columns);
        $destination = array_map(static fn (string $name): mixed => $to[$name], $columns);
        if ($source === $destination) {
            return;
        }
        $affected = $connection->executeStatement('UPDATE agentenblick_daily SET ' . $counter . ' = ' . $counter . ' - 1 WHERE day = ? AND sales_channel_id = ? AND operator = ? AND agent = ? AND kind = ? AND evidence = ? AND browser_family = ? AND ' . $counter . ' > 0', $source);
        if ($affected !== 1) {
            throw new \LogicException('missing_aggregate_for_correction');
        }
        $connection->executeStatement('INSERT INTO agentenblick_daily (day, sales_channel_id, operator, agent, kind, evidence, browser_family, requests, sessions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE ' . $counter . ' = ' . $counter . ' + 1', [...$destination, $counter === 'requests' ? 1 : 0, $counter === 'sessions' ? 1 : 0]);
        if ($counter === 'sessions' && isset($from['id'])) {
            $session = $connection->fetchAssociative('SELECT * FROM agentenblick_session WHERE id = ?', [$from['id']]);
            if ($session !== false) {
                JourneyContribution::reconcile($connection, array_replace($session, ['operator' => $to['operator'], 'agent' => $to['agent'], 'kind' => $to['kind'], 'evidence' => $to['evidence']]));
            }
        }
    }
}
