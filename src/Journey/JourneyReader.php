<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Journey;

use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Heuristics\HeuristicScore;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class JourneyReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function summary(ReportFilter $filter): array
    {
        [$where, $parameters] = $filter->where();
        $counts = [];
        foreach ($this->connection->fetchAllAssociative('SELECT evidence, metric, SUM(sessions) AS sessions FROM agentenblick_journey_daily WHERE ' . $where . ' GROUP BY evidence, metric', $parameters) as $row) {
            $counts[$row['evidence']][$row['metric']] = (int) $row['sessions'];
        }
        $funnel = $failures = [];
        foreach (Evidence::cases() as $proof) {
            $group = $counts[$proof->value] ?? [];
            $steps = [];
            foreach (Stage::cases() as $stage) {
                $steps[$stage->value] = $group['step.' . $stage->value] ?? 0;
            }
            $funnel[] = ['evidence' => $proof->value, 'sessions' => $group['sessions'] ?? 0, 'steps' => $steps];
            foreach (Failure::cases() as $failure) {
                $code = $failure->code();
                $eligible = $group['known.' . $code] ?? 0;
                $failed = $group['failure.' . $code] ?? 0;
                $failures[] = ['code' => $code, 'evidence' => $proof->value, 'sessions' => $failed, 'eligibleSessions' => $eligible, 'rate' => $eligible > 0 ? round(100 * $failed / $eligible, 1) : null];
            }
        }
        [$sessionWhere, $sessionParameters] = $this->sessionWhere($filter);
        $open = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM agentenblick_session WHERE ' . $sessionWhere . ' AND closed_at IS NULL', $sessionParameters);
        [$requestWhere, $requestParameters] = $filter->where('observed_at');
        $routes = $this->connection->fetchAllAssociative("SELECT route, stage, status, evidence, COUNT(*) AS requests FROM agentenblick_request WHERE " . $requestWhere . " AND kind <> 'human_or_unknown' AND status >= 400 GROUP BY route, stage, status, evidence ORDER BY requests DESC, route LIMIT 20", $requestParameters);
        foreach ($routes as &$route) {
            $route['status'] = (int) $route['status'];
            $route['requests'] = (int) $route['requests'];
        }
        return ['period' => $filter->period(), 'funnel' => $funnel, 'failures' => $failures, 'closedSessions' => array_sum(array_column($funnel, 'sessions')), 'openSessions' => $open, 'errorRoutes' => $routes, 'definition' => 'Independently observed steps in closed agent sessions; no missing step is inferred.'];
    }

    /** @return array<string, mixed> */
    public function sessions(ReportFilter $filter): array
    {
        [$where, $parameters] = $this->sessionWhere($filter);
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM agentenblick_session WHERE ' . $where, $parameters);
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM agentenblick_session WHERE ' . $where . ' ORDER BY last_seen DESC, id LIMIT ' . $filter->limit . ' OFFSET ' . (($filter->page - 1) * $filter->limit), $parameters);
        return ['period' => $filter->period(), 'items' => array_map($this->session(...), $rows), 'total' => $total, 'page' => $filter->page, 'limit' => $filter->limit];
    }

    /** @return array<string, mixed> */
    public function detail(string $id, ReportFilter $filter): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new NotFoundHttpException('Journey not found.');
        }
        [$where, $parameters] = $this->sessionWhere($filter);
        $row = $this->connection->fetchAssociative('SELECT * FROM agentenblick_session WHERE id = UNHEX(?) AND ' . $where, [$id, ...$parameters]);
        if ($row === false) {
            throw new NotFoundHttpException('Journey not found.');
        }
        $requests = $this->connection->fetchAllAssociative("SELECT observed_at, route, method, status, stage, failure_mask, known_mask, reason, evidence, heuristic_score, heuristic_flags, heuristic_version, heuristic_threshold, LOWER(HEX(order_id)) AS order_id FROM agentenblick_request WHERE session_id = UNHEX(?) AND kind <> 'human_or_unknown' ORDER BY observed_at, id LIMIT 501", [$id]);
        $items = [];
        foreach (array_slice($requests, 0, 500) as $request) {
            $known = [];
            foreach (['search' => 1, 'product' => 2, 'cart' => 4, 'checkout' => 8, 'order' => 16, 'http' => 32, 'payment' => 64] as $name => $bit) {
                if (((int) $request['known_mask'] & $bit) !== 0) {
                    $known[] = $name;
                }
            }
            $items[] = ['at' => self::time($request['observed_at']), 'route' => $request['route'], 'method' => $request['method'], 'status' => (int) $request['status'], 'stage' => $request['stage'] === '' ? null : $request['stage'], 'failures' => self::failures((int) $request['failure_mask']), 'knownOutcomes' => $known, 'reason' => $request['reason'], 'evidence' => $request['evidence'], 'linkage' => $row['linkage'], 'orderId' => $request['order_id'], 'heuristics' => self::heuristics($request)];
        }
        return ['session' => $this->session($row), 'items' => $items, 'truncated' => count($requests) > 500];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function session(array $row): array
    {
        $steps = [];
        foreach (Stage::cases() as $stage) {
            if (((int) $row['steps_mask'] & $stage->bit()) !== 0) {
                $steps[] = $stage->value;
            }
        }
        $failures = self::failures((int) $row['failures_mask']);
        $snapshot = JourneyContribution::snapshot($row);
        foreach ($snapshot['metrics'] ?? [] as $metric) {
            if (str_starts_with($metric, 'failure.abandoned_')) {
                $failures[] = substr($metric, 8);
            }
        }
        $reason = $this->connection->fetchOne('SELECT reason FROM agentenblick_request WHERE session_id = ? AND evidence = ? ORDER BY observed_at DESC LIMIT 1', [$row['id'], $row['evidence']]);
        $orders = $this->connection->fetchFirstColumn('SELECT DISTINCT LOWER(HEX(order_id)) FROM agentenblick_request WHERE session_id = ? AND order_id IS NOT NULL LIMIT 20', [$row['id']]);
        return ['id' => bin2hex((string) $row['id']), 'firstSeen' => self::time($row['first_seen']), 'lastSeen' => self::time($row['last_seen']), 'operator' => $row['operator'], 'agent' => $row['agent'], 'kind' => $row['kind'], 'evidence' => $row['evidence'], 'linkage' => $row['linkage'], 'lastStage' => $row['last_stage'] === '' ? null : $row['last_stage'], 'maxStage' => $steps === [] ? null : $steps[count($steps) - 1], 'steps' => $steps, 'failures' => array_values(array_unique($failures)), 'closedAt' => self::time($row['closed_at']), 'orderIds' => $orders, 'reason' => is_string($reason) ? $reason : (($row['declared_at'] ?? null) !== null && $row['evidence'] === Evidence::Declared->value ? 'webmcp_self_declaration' : null), 'requests' => (int) $row['requests'], 'detectedAt' => self::time($row['detected_at'] ?? null), 'timelineStartsAtDetection' => $row['evidence'] === 'suspected' || ($row['declared_at'] ?? null) !== null, 'observationPartial' => (bool) ($row['journey_partial'] ?? false), 'heuristics' => self::heuristics($row), 'declaration' => ($row['declared_at'] ?? null) === null ? null : ['service' => $row['declared_service'], 'intent' => $row['declared_intent'], 'at' => self::time($row['declared_at']), 'evidence' => Evidence::Declared->value]];
    }

    /** @param array<string, mixed> $row
     * @return array{score: int, flags: list<string>, version: string, threshold: int}|null
     */
    private static function heuristics(array $row): ?array
    {
        if ($row['evidence'] !== 'suspected') {
            return null;
        }
        return ['score' => (int) $row['heuristic_score'], 'flags' => HeuristicScore::codes((int) $row['heuristic_flags']), 'version' => (string) $row['heuristic_version'], 'threshold' => (int) $row['heuristic_threshold']];
    }

    /** @return array{string, list<string>} */
    private function sessionWhere(ReportFilter $filter): array
    {
        [$where, $parameters] = $filter->where('observed_at');
        return [str_replace('observed_at', 'first_seen', $where) . " AND kind <> 'human_or_unknown'", $parameters];
    }

    /** @return list<string> */
    private static function failures(int $mask): array
    {
        $codes = [];
        foreach (Failure::cases() as $failure) {
            if (($mask & $failure->value) !== 0) {
                $codes[] = $failure->code();
            }
        }
        return $codes;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? str_replace(' ', 'T', $value) . 'Z' : null;
    }
}
