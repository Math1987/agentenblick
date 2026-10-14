<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Health;

use Aithos\Agentenblick\Verification\CatalogueProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;

final class ProbeService
{
    private const RESOURCES = ['robots' => '/robots.txt', 'llms' => '/llms.txt', 'llms_full' => '/llms-full.txt', 'agents_md' => '/agents.md', 'agents_md_upper' => '/AGENTS.md', 'ucp' => '/.well-known/ucp', 'agent_card' => '/.well-known/agent-card.json', 'mcp' => '/store-api/_mcp'];

    public function __construct(private readonly Connection $connection, private readonly Domains $domains, private readonly ProbeTransport $transport, private readonly SnapshotStore $snapshots, private readonly CatalogueProvider $catalogues)
    {
    }

    /** @return array{completed: bool, checkedAt: string} */
    public function run(string $domainId, bool $autoTest = false): array
    {
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
        $domain = $this->domains->get($domainId);
        if ($this->transport->offline()) {
            throw new \RuntimeException('offline');
        }
        $lock = 'agentenblick:health:' . $domainId;
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            throw new \RuntimeException('probe_in_progress');
        }
        try {
            $deadline = microtime(true) + 20.0;
            $checkedAt = gmdate('Y-m-d\TH:i:s\Z');
            $resources = $autoTests = [];
            $policy = ['mode' => 'unknown', 'reasonCode' => 'robots_unavailable', 'groups' => []];
            $parser = null;
            foreach (self::RESOURCES as $key => $path) {
                $method = $key === 'mcp' ? 'OPTIONS' : 'GET';
                // Authority-wide robots and well-known files stay at the origin;
                // store discovery routes use the configured storefront prefix.
                $url = (in_array($key, ['robots', 'ucp', 'agent_card'], true) ? Domains::origin($domain['url']) : $domain['url']) . $path;
                $response = $this->transport->request($url, $method, 'AgentenblickHealthProbe/1', $key === 'robots', $deadline);
                $state = self::resourceState($response['statusCode']);
                $reason = $response['reasonCode'];
                $expected = in_array($key, ['ucp', 'agent_card'], true) ? ['application/json'] : ($key === 'robots' ? ['text/plain'] : ['text/plain', 'text/markdown']);
                if ($reason !== 'http_status') {
                    $state = 'unknown';
                }
                if ($state === 'available' && $key !== 'mcp' && !in_array($response['contentType'], $expected, true)) {
                    $state = 'unknown';
                    $reason = 'unexpected_content_type';
                }
                if ($key === 'robots') {
                    $policy['reasonCode'] = $reason === 'http_status' ? 'robots_unavailable' : $reason;
                    if ($response['statusCode'] === 200 && $response['reasonCode'] === 'http_status' && $response['contentType'] === 'text/plain') {
                        try {
                            $parser = new RobotsPolicy($response['body']);
                            $policy = ['mode' => 'parsed', 'reasonCode' => 'robots_rule_match', 'groups' => $parser->export()];
                        } catch (\Throwable $error) {
                            $state = 'unknown';
                            $reason = $error instanceof \LengthException ? 'robots_policy_limit' : 'robots_unsupported_document';
                            $policy['reasonCode'] = $reason;
                        }
                    } elseif ($response['statusCode'] !== null && $response['statusCode'] >= 400 && $response['statusCode'] < 500) {
                        $policy = ['mode' => 'allow', 'reasonCode' => 'robots_missing_allow', 'groups' => []];
                    } elseif (($response['statusCode'] !== null && $response['statusCode'] >= 500) || $reason === 'network_unreachable') {
                        $policy = ['mode' => 'disallow', 'reasonCode' => 'robots_unreachable_disallow', 'groups' => []];
                    }
                }
                $resources[] = ['key' => $key, 'url' => $url, 'statusCode' => $response['statusCode'], 'state' => $state, 'reasonCode' => $reason, 'checkedAt' => $checkedAt, 'probeMethod' => $method, 'protocolNotValidated' => $key === 'mcp', 'contentType' => $response['contentType']];
            }
            $rules = [];
            foreach ($this->catalogues->load()->agents() as $agent) {
                if (!is_string($agent['robotsToken'] ?? null)) {
                    continue;
                }
                $decision = $parser?->decide($agent['robotsToken'], '/') ?? ['decision' => $policy['mode'], 'matchedGroup' => null, 'matchedRule' => null, 'ruleHash' => null, 'reasonCode' => $policy['reasonCode']];
                $rules[] = ['agent' => (string) $agent['id'], 'token' => $agent['robotsToken'], 'path' => '/', ...$decision];
            }
            // Google-Extended is a policy token, never an observed user-agent identity.
            $rules[] = ['agent' => 'Google-Extended', 'token' => 'Google-Extended', 'robotsOnly' => true, 'path' => '/', ...($parser?->decide('Google-Extended', '/') ?? ['decision' => $policy['mode'], 'matchedGroup' => null, 'matchedRule' => null, 'ruleHash' => null, 'reasonCode' => $policy['reasonCode']])];
            if ($autoTest) {
                foreach (['GPTBot', 'ClaudeBot', 'ChatGPT-User'] as $agent) {
                    $response = $this->transport->request($domain['url'] . '/', 'GET', $agent, false, $deadline);
                    $autoTests[] = ['agent' => $agent, 'statusCode' => $response['statusCode'], 'reasonCode' => $response['reasonCode'], 'probeMethod' => 'GET', 'source' => 'server_ip_not_operator', 'checkedAt' => $checkedAt];
                }
            }
            $policy['domainUrl'] = $domain['url'];
            $this->snapshots->save($domain, ['checkedAt' => $checkedAt, 'resources' => $resources, 'robots' => ['state' => $policy['mode'], 'checkedAt' => $checkedAt, 'rules' => $rules, 'groups' => $policy['groups']], 'autoTests' => $autoTests], $policy);
            return ['completed' => true, 'checkedAt' => $checkedAt];
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private static function resourceState(?int $status): string
    {
        return match (true) {
            $status === null, $status >= 300 && $status < 400 => 'unknown',
            $status >= 200 && $status < 300 => 'available',
            in_array($status, [404, 410], true) => 'missing',
            in_array($status, [401, 403, 429], true) => 'blocked',
            default => 'error',
        };
    }
}
