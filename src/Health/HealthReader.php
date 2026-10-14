<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Health;

use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Journey\Stage;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class HealthReader
{
    public function __construct(private readonly Connection $connection, private readonly Domains $domains, private readonly SnapshotStore $snapshots, private readonly ProbeTransport $transport)
    {
    }

    /** SQL and saved configuration only. Never starts a probe.
     * @return array<string, mixed>
     */
    public function read(ReportFilter $filter, ?string $domainId = null): array
    {
        $domains = $this->domains->all($filter->salesChannelId);
        $domainId ??= $domains[0]['id'] ?? null;
        if ($domainId !== null && !in_array($domainId, array_column($domains, 'id'), true)) {
            throw new BadRequestHttpException('Choose a configured domain in the selected sales channel.');
        }
        $saved = $domainId === null ? null : $this->snapshots->load($domainId);
        $selected = array_values(array_filter($domains, static fn (array $domain): bool => $domain['id'] === $domainId))[0] ?? null;
        $snapshot = $saved !== null && $selected !== null && ($saved['policy']['domainUrl'] ?? '') === $selected['url'] ? $saved['snapshot'] : null;
        if ($snapshot !== null) {
            $snapshot['stale'] = $saved['checkedAt'] < gmdate('Y-m-d H:i:s', time() - 86400) || $saved['checkedAt'] > (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
            $snapshot['validForSeconds'] = 86400;
        }
        [$where, $parameters] = $filter->where();
        if ($domainId !== null) {
            $where .= ' AND domain_id = UNHEX(?)';
            $parameters[] = $domainId;
        }
        $rows = $this->connection->fetchAllAssociative('SELECT agent, evidence, route, status, code, rule_hash, rule_text, reason_code, MAX(snapshot_at) AS snapshot_at, SUM(requests) AS requests FROM agentenblick_health_daily WHERE ' . $where . ' GROUP BY agent, evidence, route, status, code, rule_hash, rule_text, reason_code ORDER BY requests DESC, code LIMIT 101', $parameters);
        $findings = [];
        foreach (array_slice($rows, 0, 100) as $row) {
            $findings[] = ['code' => $row['code'], 'severity' => in_array($row['code'], ['ucp_error', 'mcp_error'], true) ? 'error' : 'warning', 'agent' => $row['agent'], 'evidence' => $row['evidence'], 'route' => $row['route'], 'statusCode' => (int) $row['status'], 'requests' => (int) $row['requests'], 'rule' => $row['rule_text'], 'ruleHash' => $row['rule_hash'] === '' ? null : $row['rule_hash'], 'reasonCode' => $row['reason_code'], 'snapshotAt' => self::time($row['snapshot_at'])];
        }
        return ['offline' => $this->transport->offline(), 'period' => $filter->period(), 'domains' => $domains, 'selectedDomainId' => $domainId, 'snapshot' => $snapshot, 'findings' => $findings, 'findingsTruncated' => count($rows) > 100, 'afterLlms' => $this->afterLlms($filter, $domainId)];
    }

    /** @return array<string, mixed> */
    private function afterLlms(ReportFilter $filter, ?string $domainId): array
    {
        [$where, $parameters] = $filter->where('observed_at');
        if ($domainId !== null) {
            $where .= ' AND domain_id = UNHEX(?)';
            $parameters[] = $domainId;
        }
        $source = "SELECT session_id, MIN(discovery_completed_at) AS read_at FROM agentenblick_request WHERE discovery_resource IN ('llms', 'llms_full') AND status BETWEEN 200 AND 299 AND discovery_completed_at IS NOT NULL AND kind <> 'human_or_unknown' AND " . $where . ' GROUP BY session_id';
        $read = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM (' . $source . ') llms_sessions', $parameters);
        [$laterWhere, $laterParameters] = $filter->where('observed_at');
        $laterWhere = str_replace(['observed_at', 'sales_channel_id'], ['later.observed_at', 'later.sales_channel_id'], $laterWhere);
        if ($domainId !== null) {
            $laterWhere .= ' AND later.domain_id = UNHEX(?)';
            $laterParameters[] = $domainId;
        }
        $laterParameters = [...$parameters, ...$laterParameters];
        // An observed request after a successful llms response is not evidence
        // that its content was read, understood or caused the later action.
        $later = (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT later.session_id) FROM (' . $source . ') llms_events INNER JOIN agentenblick_request later ON later.session_id = llms_events.session_id AND later.observed_at > llms_events.read_at WHERE ' . $laterWhere, $laterParameters);
        $counts = $this->connection->fetchAllKeyValue("SELECT later.stage, COUNT(DISTINCT later.session_id) FROM (" . $source . ") llms_events INNER JOIN agentenblick_request later ON later.session_id = llms_events.session_id AND later.observed_at > llms_events.read_at WHERE later.stage <> '' AND " . $laterWhere . ' GROUP BY later.stage', $laterParameters);
        $steps = [];
        foreach (Stage::cases() as $stage) {
            $steps[] = ['stage' => $stage->value, 'sessions' => (int) ($counts[$stage->value] ?? 0)];
        }
        return ['sessionsReadLlms' => $read, 'sessionsWithLaterRequests' => $later, 'steps' => $steps, 'definition' => 'Observed requests after a successful llms.txt or llms-full.txt response; reading and causation are not inferred.'];
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) ? str_replace(' ', 'T', $value) . 'Z' : null;
    }
}
