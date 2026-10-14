<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Admin;

use Aithos\Agentenblick\Persistence\ProxyDiagnostics;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class AnalyticsReader
{
    public function __construct(private readonly Connection $connection, private readonly ProxyDiagnostics $diagnostics)
    {
    }

    /** Shared by the CLI and admin: only daily counters, including human denominators.
     * @return list<array<string, mixed>>
     */
    public function groups(ReportFilter $filter): array
    {
        [$where, $parameters] = $filter->where();
        $rows = $this->connection->fetchAllAssociative('SELECT operator, agent, kind, evidence, SUM(requests) AS requests, SUM(sessions) AS sessions FROM agentenblick_daily WHERE ' . $where . ' GROUP BY operator, agent, kind, evidence HAVING SUM(requests) > 0 OR SUM(sessions) > 0 ORDER BY requests DESC, operator, agent, kind, evidence LIMIT 5001', $parameters);
        if (count($rows) > 5000) {
            throw new ConflictHttpException('Too many analytics groups; select one sales channel.');
        }
        return array_map(self::counts(...), $rows);
    }

    /** @param list<array<string, mixed>> $groups
     * @return array{requests: int, sessions: int, agentRequests: int, agentSessions: int, suspectedRequests: int, suspectedSessions: int, agentRequestsPct: ?float, agentSessionsPct: ?float}
     */
    public static function totals(array $groups): array
    {
        $totals = ['requests' => 0, 'sessions' => 0, 'agentRequests' => 0, 'agentSessions' => 0, 'suspectedRequests' => 0, 'suspectedSessions' => 0];
        foreach ($groups as $row) {
            $totals['requests'] += (int) $row['requests'];
            $totals['sessions'] += (int) $row['sessions'];
            if ($row['evidence'] === 'suspected') {
                $totals['suspectedRequests'] += (int) $row['requests'];
                $totals['suspectedSessions'] += (int) $row['sessions'];
            } elseif ($row['kind'] !== 'human_or_unknown') {
                $totals['agentRequests'] += (int) $row['requests'];
                $totals['agentSessions'] += (int) $row['sessions'];
            }
        }
        return $totals + ['agentRequestsPct' => $totals['requests'] > 0 ? round(100 * $totals['agentRequests'] / $totals['requests'], 1) : null, 'agentSessionsPct' => $totals['sessions'] > 0 ? round(100 * $totals['agentSessions'] / $totals['sessions'], 1) : null];
    }

    /** @return array<string, mixed> */
    public function summary(ReportFilter $filter, string $languageId = Defaults::LANGUAGE_SYSTEM): array
    {
        $groups = $this->groups($filter);
        $agentGroups = array_values(array_filter($groups, static fn (array $row): bool => $row['kind'] !== 'human_or_unknown'));
        [$where, $parameters] = $filter->where();
        $dailyRows = $this->connection->fetchAllAssociative("SELECT day, SUM(requests) AS requests, SUM(sessions) AS sessions, SUM(IF(kind <> 'human_or_unknown' AND evidence <> 'suspected', requests, 0)) AS agentRequests, SUM(IF(kind <> 'human_or_unknown' AND evidence <> 'suspected', sessions, 0)) AS agentSessions, SUM(IF(evidence = 'suspected', requests, 0)) AS suspectedRequests, SUM(IF(evidence = 'suspected', sessions, 0)) AS suspectedSessions FROM agentenblick_daily WHERE " . $where . ' GROUP BY day', $parameters);
        $dailyByDate = [];
        foreach ($dailyRows as $row) {
            $dailyByDate[$row['day']] = ['date' => $row['day'], 'requests' => (int) $row['requests'], 'sessions' => (int) $row['sessions'], 'agentRequests' => (int) $row['agentRequests'], 'agentSessions' => (int) $row['agentSessions'], 'suspectedRequests' => (int) $row['suspectedRequests'], 'suspectedSessions' => (int) $row['suspectedSessions']];
        }
        $period = $filter->period();
        $date = new \DateTimeImmutable($period['since'], new \DateTimeZone('UTC'));
        $daily = [];
        for ($i = 0; $i < $filter->days; ++$i) {
            $day = $date->format('Y-m-d');
            $daily[] = $dailyByDate[$day] ?? ['date' => $day, 'requests' => 0, 'sessions' => 0, 'agentRequests' => 0, 'agentSessions' => 0, 'suspectedRequests' => 0, 'suspectedSessions' => 0];
            $date = $date->modify('+1 day');
        }
        return [
            'period' => $period,
            'scope' => 'PHP-visible main requests; identified or declared agents, not all AI usage. Suspected automation is reported separately, without an asserted AI identity. Sessions are provisional.',
            'totals' => self::totals($groups), 'daily' => $daily,
            'byKind' => self::breakdown(array_values(array_filter($agentGroups, static fn (array $row): bool => $row['evidence'] !== 'suspected')), 'kind'), 'byEvidence' => self::breakdown($agentGroups, 'evidence'),
            'humanBrowsers' => $this->humanBrowsers($filter),
            'proxyDiagnostics' => $this->diagnostics->report(),
            'salesChannels' => $this->connection->fetchAllAssociative("SELECT LOWER(HEX(s.id)) AS id, COALESCE(t.name, fallback.name, LOWER(HEX(s.id))) AS name FROM sales_channel s LEFT JOIN sales_channel_translation t ON t.sales_channel_id = s.id AND t.language_id = UNHEX(?) LEFT JOIN sales_channel_translation fallback ON fallback.sales_channel_id = s.id AND fallback.language_id = UNHEX(?) ORDER BY name, id", [$languageId, Defaults::LANGUAGE_SYSTEM]),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function humanBrowsers(ReportFilter $filter): array
    {
        [$where, $parameters] = $filter->where();
        return array_map(self::counts(...), $this->connection->fetchAllAssociative("SELECT browser_family, SUM(requests) AS requests, SUM(sessions) AS sessions FROM agentenblick_daily WHERE " . $where . " AND kind = 'human_or_unknown' GROUP BY browser_family ORDER BY requests DESC", $parameters));
    }

    /** @return array<string, mixed> */
    public function agents(ReportFilter $filter, bool $export = false): array
    {
        $agents = [];
        foreach ($this->groups($filter) as $row) {
            if ($row['kind'] === 'human_or_unknown') {
                continue;
            }
            $identity = $row['operator'] . "\0" . $row['agent'];
            $agents[$identity] ??= ['operator' => $row['operator'], 'agent' => $row['agent'], 'kind' => $row['kind'], 'evidence' => $row['evidence'], 'requests' => 0, 'sessions' => 0, 'byEvidence' => [], 'lastSeen' => null, 'topRoutes' => []];
            $agents[$identity]['requests'] += $row['requests'];
            $agents[$identity]['sessions'] += $row['sessions'];
            if ($agents[$identity]['kind'] !== $row['kind']) {
                $agents[$identity]['kind'] = 'mixed';
            }
            if ($agents[$identity]['evidence'] !== $row['evidence']) {
                $agents[$identity]['evidence'] = 'mixed';
            }
            $proof = $row['evidence'];
            $agents[$identity]['byEvidence'][$proof] ??= ['value' => $proof, 'requests' => 0, 'sessions' => 0];
            $agents[$identity]['byEvidence'][$proof]['requests'] += $row['requests'];
            $agents[$identity]['byEvidence'][$proof]['sessions'] += $row['sessions'];
        }
        uasort($agents, static fn (array $a, array $b): int => ($b['requests'] <=> $a['requests']) ?: strcmp($a['operator'] . $a['agent'], $b['operator'] . $b['agent']));
        $total = count($agents);
        $items = $export ? $agents : array_slice($agents, ($filter->page - 1) * $filter->limit, $filter->limit, true);
        if ($items !== []) {
            // Fetch aggregate route counts only for the displayed agents. No human
            // sessions or raw headers are ever read by the administration.
            [$where, $parameters] = $filter->where('observed_at');
            $pairs = [];
            foreach ($items as $item) {
                $pairs[] = '(?, ?)';
                $parameters[] = $item['operator'];
                $parameters[] = $item['agent'];
            }
            $where .= " AND kind <> 'human_or_unknown' AND (operator, agent) IN (" . implode(', ', $pairs) . ')';
            foreach ($this->connection->fetchAllAssociative('SELECT operator, agent, MAX(observed_at) AS last_seen FROM agentenblick_request WHERE ' . $where . ' GROUP BY operator, agent', $parameters) as $row) {
                $items[$row['operator'] . "\0" . $row['agent']]['lastSeen'] = str_replace(' ', 'T', $row['last_seen']) . 'Z';
            }
            $routes = $this->connection->fetchAllAssociative('SELECT operator, agent, route, requests FROM (SELECT operator, agent, route, COUNT(*) AS requests, ROW_NUMBER() OVER (PARTITION BY operator, agent ORDER BY COUNT(*) DESC, route) AS ranking FROM agentenblick_request WHERE ' . $where . ' GROUP BY operator, agent, route) ranked WHERE ranking <= 5 ORDER BY operator, agent, ranking', $parameters);
            foreach ($routes as $route) {
                $items[$route['operator'] . "\0" . $route['agent']]['topRoutes'][] = ['route' => $route['route'], 'requests' => (int) $route['requests']];
            }
        }
        foreach ($items as &$item) {
            $item['byEvidence'] = array_values($item['byEvidence'] ?? []);
        }
        unset($item);
        return ['period' => $filter->period(), 'items' => array_values($items), 'total' => $total, 'page' => $export ? 1 : $filter->page, 'limit' => $export ? $total : $filter->limit];
    }

    /** @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>>
     */
    private static function breakdown(array $groups, string $field): array
    {
        $result = [];
        foreach ($groups as $row) {
            $value = $row[$field];
            $result[$value] ??= ['value' => $value, 'requests' => 0, 'sessions' => 0];
            $result[$value]['requests'] += $row['requests'];
            $result[$value]['sessions'] += $row['sessions'];
        }
        return array_values($result);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function counts(array $row): array
    {
        $row['requests'] = (int) $row['requests'];
        $row['sessions'] = (int) $row['sessions'];
        return $row;
    }
}
