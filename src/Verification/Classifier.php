<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

final class Classifier
{
    public function __construct(private readonly Connection $connection, private readonly KeyDirectory $directories, private readonly ReverseDns $reverseDns)
    {
    }

    public function classify(Request $request, Catalogue $catalogue, string $ipHmac = '', ?int $observedAt = null): Classification
    {
        if (self::hasWebBotAuthCandidate($request)) {
            return (new WebBotAuth())->verify(
                $request,
                $catalogue,
                fn (string $origin): array => $this->directories->get($origin, $catalogue, !$request->headers->has('Signature-Agent')),
                $observedAt ?? time(),
                fn (string $keyId): ?string => $this->directories->knownOrigin($keyId, $catalogue),
            );
        }
        $agent = $catalogue->match(substr($request->headers->get('User-Agent') ?? '', 0, 1024));
        if ($agent === null) {
            return new Classification();
        }
        $ranges = $agent['ranges'] ?? [];
        $stored = $this->connection->fetchOne('SELECT ranges_json FROM agentenblick_ip_ranges WHERE agent = ?', [$agent['id']]);
        if (is_string($stored)) {
            $ranges = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        }
        // Symfony is the sole authority for resolving the client through trusted proxies.
        $ip = $request->getClientIp();
        $verified = $ip !== null && $ranges !== [] && IpUtils::checkIp($ip, $ranges);
        if (!$verified && $ipHmac !== '' && $this->reverseDns->verify($ip, $ipHmac, $agent['operator'], $agent['rdnsSuffixes'] ?? [])) {
            return new Classification($agent['operator'], $agent['id'], Kind::from($agent['kind']), Evidence::Rdns, 'forward_confirmed_reverse_dns');
        }
        return new Classification(
            $agent['operator'],
            $agent['id'],
            Kind::from($agent['kind']),
            $verified ? Evidence::Ip : Evidence::Declared,
            $verified ? 'ip_in_official_range' : 'identity_not_verified',
        );
    }

    public static function hasWebBotAuthCandidate(Request $request): bool
    {
        try {
            foreach ((new HttpSignature())->parse($request->headers->get('Signature-Input') ?? '', $request->headers->get('Signature') ?? '') as $candidate) {
                if ($candidate['input']->parameterByKey('tag') === 'web-bot-auth') {
                    return true;
                }
            }
        } catch (\Throwable) {
            // An ordinary or malformed HTTP signature is not evidence of an AI agent.
        }
        return false;
    }
}
