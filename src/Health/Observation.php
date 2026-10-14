<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Health;

use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Verification\CatalogueProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class Observation
{
    public function __construct(private readonly Connection $connection, private readonly Domains $domains, private readonly SnapshotStore $snapshots, private readonly CatalogueProvider $catalogues)
    {
    }

    /** @param array<string, mixed> $row The committed, minimized observation. */
    public function record(Request $original, array $row): void
    {
        if ($row['kind'] === Kind::HumanOrUnknown->value) {
            return;
        }
        $domain = $this->domain($original, (string) $row['sales_channel_id']);
        if ($domain === null) {
            return;
        }
        $path = self::relativePath($original, $domain['url']);
        if ((int) $row['status'] >= 400) {
            if ($path === '/.well-known/ucp' || str_starts_with($path, '/ucp/')) {
                $this->finding($domain['id'], $row, 'ucp_error');
            } elseif ($path === '/store-api/_mcp' || $path === '/mcp' || str_starts_with($path, '/mcp/')) {
                $this->finding($domain['id'], $row, 'mcp_error');
            }
        }
        $snapshot = $this->snapshots->load($domain['id']);
        if ($snapshot === null || $snapshot['checkedAt'] > (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v') || $snapshot['checkedAt'] > (new \DateTimeImmutable((string) $row['observed_at'], new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v') || $snapshot['checkedAt'] < gmdate('Y-m-d H:i:s', time() - 86400) || ($snapshot['policy']['domainUrl'] ?? '') !== $domain['url']) {
            return;
        }
        $token = null;
        foreach ($this->catalogues->load()->agents() as $agent) {
            if (($agent['id'] ?? null) === $row['agent'] && is_string($agent['robotsToken'] ?? null)) {
                $token = $agent['robotsToken'];
                break;
            }
        }
        if ($token === null) {
            return;
        }
        $policy = $snapshot['policy'];
        $decision = ($policy['mode'] ?? null) === 'parsed'
            ? RobotsPolicy::restore($policy['groups'] ?? null)->decide($token, $original->getRequestUri())
            : ['decision' => $policy['mode'] ?? 'unknown', 'matchedRule' => null, 'ruleHash' => null, 'reasonCode' => $policy['reasonCode'] ?? 'robots_unavailable'];
        if ($decision['decision'] === 'allow' && in_array((int) $row['status'], [403, 429], true)) {
            $this->finding($domain['id'], $row, 'allowed_but_blocked', $decision, $snapshot['checkedAt']);
        } elseif ($decision['decision'] === 'disallow') {
            $this->finding($domain['id'], $row, 'disallowed_but_observed', $decision, $snapshot['checkedAt']);
        }
    }

    /** Resolve only against configured domains before minimizing any detail. */
    public function prepare(Request $request): void
    {
        $channel = $request->attributes->get('sw-sales-channel-id');
        $domain = is_string($channel) ? $this->domain($request, $channel) : null;
        $request->attributes->set('sw-domain-id', $domain['id'] ?? null);
        $request->attributes->set('_agentenblick_discovery_resource', $domain === null ? '' : self::resourcePath(self::relativePath($request, $domain['url'])));
    }

    public static function resource(Request $request): string
    {
        $prepared = $request->attributes->get('_agentenblick_discovery_resource');
        return is_string($prepared) ? $prepared : self::resourcePath($request->getPathInfo());
    }

    private static function resourcePath(string $path): string
    {
        return match ($path) {
            '/robots.txt' => 'robots', '/llms.txt' => 'llms', '/llms-full.txt' => 'llms_full',
            '/agents.md', '/AGENTS.md' => 'agents_md', '/.well-known/ucp' => 'ucp',
            '/.well-known/agent-card.json' => 'agent_card', '/store-api/_mcp' => 'mcp',
            default => '',
        };
    }

    private static function relativePath(Request $request, string $domainUrl): string
    {
        $base = rtrim((string) parse_url($domainUrl, PHP_URL_PATH), '/');
        // domain() already enforced the complete prefix boundary.
        return substr($request->getPathInfo(), strlen($base)) ?: '/';
    }

    /** @return array{id: string, salesChannelId: string, url: string, eligible: bool, reasonCode: string}|null */
    private function domain(Request $request, string $channel): ?array
    {
        $best = null;
        $length = -1;
        $ambiguous = false;
        foreach ($this->domains->all($channel) as $domain) {
            $base = parse_url($domain['url']);
            if ($base === false || ($base['scheme'] ?? '') !== $request->getScheme() || (int) ($base['port'] ?? (($base['scheme'] ?? '') === 'https' ? 443 : 80)) !== $request->getPort() || strcasecmp((string) ($base['host'] ?? ''), $request->getHost()) !== 0) {
                continue;
            }
            $prefix = rtrim((string) ($base['path'] ?? ''), '/');
            if ($prefix !== '' && $request->getPathInfo() !== $prefix && !str_starts_with($request->getPathInfo(), $prefix . '/')) {
                continue;
            }
            if (strlen($prefix) > $length) {
                $best = $domain;
                $length = strlen($prefix);
                $ambiguous = false;
            } elseif (strlen($prefix) === $length) {
                $ambiguous = true;
            }
        }
        return $ambiguous ? null : $best;
    }

    /** @param array<string, mixed> $row
     * @param array<string, mixed> $decision Public robots policy, never the tested URI.
     */
    private function finding(string $domainId, array $row, string $code, array $decision = [], ?string $snapshotAt = null): void
    {
        $this->connection->executeStatement('INSERT INTO agentenblick_health_daily (day, domain_id, sales_channel_id, agent, evidence, route, status, code, rule_hash, rule_text, reason_code, snapshot_at, requests) VALUES (?, UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE requests = requests + 1, snapshot_at = VALUES(snapshot_at)', [$row['day'], $domainId, $row['sales_channel_id'], $row['agent'], $row['evidence'], $row['route'], $row['status'], $code, $decision['ruleHash'] ?? '', $decision['matchedRule'] ?? null, $decision['reasonCode'] ?? 'http_status', $snapshotAt]);
    }
}
