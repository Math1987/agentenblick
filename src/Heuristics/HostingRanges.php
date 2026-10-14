<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Heuristics;

use Symfony\Component\HttpFoundation\IpUtils;

/** A deliberately incomplete, offline hint. A match does not identify a bot or ASN. */
final class HostingRanges
{
    /** @var list<string>|null */
    private ?array $ranges = null;

    public function __construct(private readonly ?string $path = null)
    {
    }

    public function contains(?string $ip, bool $isCdn = false): bool
    {
        if ($isCdn || $ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        try {
            return IpUtils::checkIp($ip, $this->ranges());
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function ranges(): array
    {
        if ($this->ranges !== null) {
            return $this->ranges;
        }
        $this->ranges = [];
        $path = $this->path ?? dirname(__DIR__) . '/Resources/hosting-ranges.json';
        if (!is_file($path) || filesize($path) > 65536) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $document = json_decode($raw, true, 8);
        if (!is_array($document) || ($document['schema'] ?? '') !== 'agentenblick.hosting/1' || !is_array($document['ranges'] ?? null) || count($document['ranges']) > 512) {
            return [];
        }
        foreach ($document['ranges'] as $range) {
            if (!is_string($range) || !str_contains($range, '/')) {
                return [];
            }
            [$address, $bits] = explode('/', $range, 2);
            $packed = inet_pton($address);
            if ($packed === false || !ctype_digit($bits) || (int) $bits < 1 || (int) $bits > strlen($packed) * 8) {
                return [];
            }
        }
        /** @var list<string> $ranges */
        $ranges = array_values($document['ranges']);
        return $this->ranges = $ranges;
    }
}
