<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Aithos\Agentenblick\Domain\Kind;

final class Catalogue
{
    /** @var list<array<string, mixed>> */
    private array $agents;
    /** @var list<string> */
    private array $cdnRanges = [];

    public function __construct(string $path, string $environment = 'prod')
    {
        $document = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($document) || !isset($document['agents']) || !is_array($document['agents'])) {
            throw new \UnexpectedValueException('invalid_catalogue');
        }
        if (($document['testOnly'] ?? false) && $environment !== 'test') {
            throw new \LogicException('test_catalogue_forbidden');
        }
        foreach ($document['agents'] as $agent) {
            if (!is_array($agent) || !isset($agent['id'], $agent['operator'], $agent['pattern'], $agent['kind'])
                || Kind::tryFrom($agent['kind']) === null || @preg_match($agent['pattern'], '') === false) {
                throw new \UnexpectedValueException('invalid_catalogue_entry');
            }
        }
        $this->agents = array_values($document['agents']);
        foreach ($document['cdnRanges'] ?? [] as $provider) {
            foreach ($provider['ranges'] ?? [] as $range) {
                $this->cdnRanges[] = $range;
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function agents(): array
    {
        return $this->agents;
    }

    public function isCdn(?string $ip): bool
    {
        return $ip !== null && $this->cdnRanges !== [] && \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $this->cdnRanges);
    }

    /** @return array<string, mixed>|null */
    public function match(string $userAgent): ?array
    {
        foreach ($this->agents as $agent) {
            if (preg_match($agent['pattern'], $userAgent) === 1) {
                return $agent;
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    public function origin(string $origin): ?array
    {
        foreach ($this->agents as $agent) {
            if (($agent['origin'] ?? null) === $origin) {
                return $agent;
            }
        }
        return null;
    }
}
