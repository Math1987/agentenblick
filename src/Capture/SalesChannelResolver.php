<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Capture;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/** Resolve cache hits after response using configured domains, never visitor-supplied IDs. */
final class SalesChannelResolver
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function resolve(Request $request): void
    {
        if ($request->attributes->has('sw-sales-channel-id') && $request->attributes->has('sw-domain-id')) {
            return;
        }
        $domains = $this->connection->fetchAllAssociative('SELECT url, LOWER(HEX(sales_channel_id)) AS id, LOWER(HEX(id)) AS domain_id FROM sales_channel_domain');
        $id = self::match($request, $domains);
        if ($id !== null) {
            $request->attributes->set('sw-sales-channel-id', $id);
        }
        $domainId = self::match($request, array_map(static fn (array $domain): array => ['url' => $domain['url'], 'id' => $domain['domain_id']], $domains));
        if ($domainId !== null) {
            $request->attributes->set('sw-domain-id', $domainId);
        }
    }

    /** @param list<array<string, mixed>> $domains */
    public static function match(Request $request, array $domains): ?string
    {
        $origin = strtolower($request->getSchemeAndHttpHost());
        $path = $request->getPathInfo();
        $selected = null;
        $length = -1;
        foreach ($domains as $domain) {
            if (!is_string($domain['url'] ?? null) || !is_string($domain['id'] ?? null)) {
                continue;
            }
            $parts = parse_url($domain['url']);
            if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
                continue;
            }
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            $default = ($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80);
            $candidateOrigin = strtolower($parts['scheme'] . '://' . $parts['host'] . ($default ? '' : ':' . $port));
            $base = rtrim($parts['path'] ?? '', '/');
            if ($origin !== $candidateOrigin || ($base !== '' && $path !== $base && !str_starts_with($path, $base . '/'))) {
                continue;
            }
            if (strlen($base) > $length) {
                $selected = $domain['id'];
                $length = strlen($base);
            } elseif (strlen($base) === $length && $selected !== $domain['id']) {
                // Ambiguous domain configuration must not attribute to an arbitrary channel.
                $selected = null;
            }
        }
        return $selected;
    }
}
