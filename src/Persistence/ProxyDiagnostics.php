<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Persistence;

use Doctrine\DBAL\Connection;

final class ProxyDiagnostics
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(bool $cdn): void
    {
        $hour = gmdate('Y-m-d-H');
        foreach (['proxy_total:' . $hour, $cdn ? 'proxy_cdn:' . $hour : 'proxy_noncdn:' . $hour] as $name) {
            $this->connection->executeStatement('INSERT INTO agentenblick_state (name, value, updated_at) VALUES (?, \'1\', ?) ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1, updated_at = VALUES(updated_at)', [$name, gmdate('Y-m-d H:i:s')]);
        }
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $hour = gmdate('Y-m-d-H');
        $total = (int) $this->connection->fetchOne('SELECT value FROM agentenblick_state WHERE name = ?', ['proxy_total:' . $hour]);
        $cdn = (int) $this->connection->fetchOne('SELECT value FROM agentenblick_state WHERE name = ?', ['proxy_cdn:' . $hour]);
        $warning = $total >= 10 && $cdn > $total / 2;
        return [
            'window' => $hour . ' UTC', 'requests' => $total, 'cdn_resolved_client_ips' => $cdn,
            'warning' => $warning,
            'message' => $warning ? 'Most resolved client IPs belong to known CDN ranges; IP verification may be unreliable. Check trusted proxies before interpreting unverified agents.' : null,
            'remediation' => 'Configure only your actual proxy addresses in TRUSTED_PROXIES and Symfony trusted_headers; make the proxy overwrite forwarded headers, then verify Request::getClientIp(). Never trust visitor-supplied X-Forwarded-For or CF-Connecting-IP directly. The warning resets with the next hourly window.',
        ];
    }
}
