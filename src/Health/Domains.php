<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Health;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class Domains
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<array{id: string, salesChannelId: string, url: string, eligible: bool, reasonCode: string}> */
    public function all(?string $channel = null): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT LOWER(HEX(d.id)) AS id, LOWER(HEX(d.sales_channel_id)) AS salesChannelId, d.url FROM sales_channel_domain d INNER JOIN sales_channel c ON c.id = d.sales_channel_id WHERE c.active = 1' . ($channel === null ? '' : ' AND d.sales_channel_id = UNHEX(?)') . ' ORDER BY d.url, d.id LIMIT 100', $channel === null ? [] : [$channel]);
        $result = [];
        foreach ($rows as $row) {
            $url = rtrim((string) $row['url'], '/');
            $parts = parse_url($url);
            if ($parts === false || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
                continue;
            }
            $eligible = self::safeUrl($url) !== null;
            $result[] = ['id' => (string) $row['id'], 'salesChannelId' => (string) $row['salesChannelId'], 'url' => $url, 'eligible' => $eligible, 'reasonCode' => $eligible ? 'public_https' : 'public_https_required'];
        }
        return $result;
    }

    /** @return array{id: string, salesChannelId: string, url: string, eligible: bool, reasonCode: string} */
    public function get(string $id): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id)) {
            foreach ($this->all() as $domain) {
                if ($domain['id'] === $id && $domain['eligible']) {
                    return $domain;
                }
            }
        }
        throw new NotFoundHttpException('Configured public HTTPS domain not found.');
    }

    public static function safeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || strlen($url) > 2048
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host) || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        return 'https://' . $host . rtrim($parts['path'] ?? '', '/');
    }

    public static function origin(string $url): string
    {
        return 'https://' . parse_url($url, PHP_URL_HOST);
    }
}
