<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Benchmark;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Transport
{
    public const ORIGIN = 'https://shopware.aithos.app';
    private readonly HttpClientInterface $client;

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? new NoPrivateNetworkHttpClient(HttpClient::create());
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function request(string $method, string $path, string $body = '', ?string $installation = null, ?string $secret = null): array
    {
        if (!preg_match('#^/v1/(?:installations(?:/[a-f0-9-]{36})?|submissions|benchmarks(?:\?[A-Za-z0-9%=&._~-]*)?)$#D', $path) || strlen($body) > 16384) {
            throw new \InvalidArgumentException('Invalid benchmark request.');
        }
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($installation !== null && $secret !== null) {
            $timestamp = (string) time();
            $headers += ['X-Agentenblick-Installation' => $installation, 'X-Agentenblick-Timestamp' => $timestamp, 'X-Agentenblick-Signature' => self::signature($secret, $timestamp, $method, $path, $body)];
        }
        $response = $this->client->request($method, self::ORIGIN . $path, [
            'headers' => $headers, 'body' => $body, 'max_redirects' => 0, 'timeout' => 6.0, 'max_duration' => 6.0, 'buffer' => false,
        ]);
        try {
            $status = $response->getStatusCode();
            $content = '';
            foreach ($this->client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException('benchmark_timeout');
                }
                $content .= $chunk->getContent();
                if (strlen($content) > 65536) {
                    throw new \RuntimeException('benchmark_response_too_large');
                }
            }
            $document = $content === '' ? [] : json_decode($content, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                throw new \RuntimeException('benchmark_invalid_response');
            }
            return ['status' => $status, 'body' => $document];
        } finally {
            $response->cancel();
        }
    }

    public static function signature(string $secret, string $timestamp, string $method, string $path, string $bytes): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $method . "\n" . $path . "\n" . $bytes, $secret);
    }
}
