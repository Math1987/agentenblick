<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Backport the CVE-2026-48736 boundary on older supported Shopware dependencies. */
final class PublicNetworkClient
{
    private const TRANSITION_SUBNETS = [
        '::/96',
        '2002::/16',
        '2001::/32',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
    ];

    public static function protect(HttpClientInterface $client): HttpClientInterface
    {
        // Keep Symfony's default checks, including those beyond PRIVATE_SUBNETS.
        // An explicit subnet argument replaces, rather than extends, that policy.
        $defaults = new NoPrivateNetworkHttpClient($client);
        return new NoPrivateNetworkHttpClient($defaults, array_values(array_unique([
            ...IpUtils::PRIVATE_SUBNETS,
            ...self::TRANSITION_SUBNETS,
        ])));
    }
}
