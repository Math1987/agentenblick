<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

final class SafeFetcher
{
    /** @return array<string, mixed> */
    public function json(string $url, int $maximumBytes = 65536, bool $testOnly = false): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (!$testOnly && (($parts['scheme'] ?? '') !== 'https' || ($parts['port'] ?? 443) !== 443))) {
            throw new \UnexpectedValueException('invalid_source_url');
        }
        $client = HttpClient::create();
        if (!$testOnly) {
            $client = new NoPrivateNetworkHttpClient($client);
        }
        $response = $client->request('GET', $url, [
            'max_redirects' => 0, 'timeout' => 2.0, 'max_duration' => 2.0, 'buffer' => false,
            'headers' => ['Accept' => 'application/json'],
        ]);
        try {
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('source_unavailable');
            }
            $body = '';
            foreach ($client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException('source_timeout');
                }
                $body .= $chunk->getContent();
                if (strlen($body) > $maximumBytes) {
                    throw new \LengthException('source_too_large');
                }
            }
            $document = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                throw new \UnexpectedValueException('source_not_object');
            }
            return $document;
        } finally {
            $response->cancel();
        }
    }
}
