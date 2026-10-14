<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Health;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/** One-use, 60-second self-test capability; no fixed header can suppress analytics. */
final class ProbeTokens
{
    public const HEADER = 'X-Agentenblick-Health-Probe';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issue(string $method, string $url, string $userAgent): string
    {
        $id = bin2hex(random_bytes(16));
        $secret = random_bytes(32);
        $binding = self::binding($method, (string) parse_url($url, PHP_URL_SCHEME), (string) parse_url($url, PHP_URL_HOST), (int) (parse_url($url, PHP_URL_PORT) ?: 443), (string) parse_url($url, PHP_URL_PATH), $userAgent);
        $token = $id . '.' . hash_hmac('sha256', $binding, $secret);
        sodium_memzero($secret);
        $this->connection->insert('agentenblick_state', ['name' => 'health_probe:' . $id, 'value' => json_encode(['digest' => hash('sha256', $token), 'binding' => $binding, 'expires' => time() + 60], JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return $token;
    }

    public function consume(Request $request): bool
    {
        $token = $request->headers->get(self::HEADER);
        if (!is_string($token) || preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/D', $token) !== 1) {
            return false;
        }
        try {
            return $this->connection->transactional(function (Connection $connection) use ($token, $request): bool {
                $name = 'health_probe:' . substr($token, 0, 32);
                $raw = $connection->fetchOne('SELECT value FROM agentenblick_state WHERE name = ? FOR UPDATE', [$name]);
                $state = is_string($raw) ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : null;
                if (!is_array($state) || !is_string($state['digest'] ?? null) || !is_string($state['binding'] ?? null) || !is_int($state['expires'] ?? null)
                    || $state['expires'] <= time() || $state['expires'] > time() + 60
                    || !hash_equals($state['digest'], hash('sha256', $token))
                    || !hash_equals($state['binding'], self::binding($request->getMethod(), $request->getScheme(), $request->getHost(), (int) $request->getPort(), $request->getRequestUri(), $request->headers->get('User-Agent') ?? ''))) {
                    return false;
                }
                $connection->delete('agentenblick_state', ['name' => $name]);
                return true;
            });
        } catch (\Throwable) {
            // Invalid or unavailable capabilities never suppress observation.
            return false;
        }
    }

    public function revoke(string $token): void
    {
        $this->connection->delete('agentenblick_state', ['name' => 'health_probe:' . substr($token, 0, 32)]);
    }

    private static function binding(string $method, string $scheme, string $host, int $port, string $uri, string $agent): string
    {
        return hash('sha256', strtoupper($method) . "\n" . strtolower($scheme) . ":" . $port . "\n" . strtolower($host) . "\n" . ($uri === '' ? '/' : $uri) . "\n" . $agent);
    }
}
