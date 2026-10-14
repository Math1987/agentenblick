<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Health;

use Aithos\Agentenblick\Verification\PublicNetworkClient;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ProbeTransport
{
    public function __construct(private readonly ProbeTokens $tokens, private readonly SystemConfigService $config, private readonly ?HttpClientInterface $transport = null)
    {
    }

    public function offline(): bool
    {
        return ($this->config->getDomain('AithosAgentenblick.config')['AithosAgentenblick.config.offline'] ?? false) === true;
    }

    /** @return array{statusCode: ?int, body: string, contentType: string, reasonCode: string} */
    public function request(string $url, string $method, string $agent, bool $readBody, float $deadline): array
    {
        if ($this->offline()) {
            return ['statusCode' => null, 'body' => '', 'contentType' => '', 'reasonCode' => 'offline'];
        }
        if (!in_array($method, ['GET', 'OPTIONS'], true) || Domains::safeUrl($url) === null) {
            throw new \InvalidArgumentException('public_https_required');
        }
        $remaining = $deadline - microtime(true);
        if ($remaining < 0.1) {
            return ['statusCode' => null, 'body' => '', 'contentType' => '', 'reasonCode' => 'budget_exhausted'];
        }
        $client = PublicNetworkClient::protect($this->transport ?? HttpClient::create());
        $token = $this->tokens->issue($method, $url, $agent);
        $response = null;
        $status = null;
        try {
            $response = $client->request($method, $url, ['headers' => ['User-Agent' => $agent, ProbeTokens::HEADER => $token, 'Accept' => $readBody ? 'text/plain' : '*/*'], 'max_redirects' => 0, 'timeout' => min(2.0, $remaining), 'max_duration' => min(2.0, $remaining), 'buffer' => false]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $contentType = strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0]));
            $contentType = in_array($contentType, ['text/plain', 'text/markdown', 'text/html', 'application/json'], true) ? $contentType : 'other';
            $body = '';
            if ($readBody && $status >= 200 && $status < 300) {
                foreach ($client->stream($response) as $chunk) {
                    if ($chunk->isTimeout() || microtime(true) >= $deadline) {
                        throw new \RuntimeException('probe_timeout');
                    }
                    $body .= $chunk->getContent();
                    if (strlen($body) > RobotsPolicy::MAX_BYTES) {
                        return ['statusCode' => $status, 'body' => '', 'contentType' => '', 'reasonCode' => 'response_too_large'];
                    }
                }
            }
            return ['statusCode' => $status, 'body' => $body, 'contentType' => $contentType, 'reasonCode' => $status >= 300 && $status < 400 ? 'redirect_not_followed' : 'http_status'];
        } catch (\Throwable) {
            return ['statusCode' => $status, 'body' => '', 'contentType' => '', 'reasonCode' => 'network_unreachable'];
        } finally {
            $response?->cancel();
            $this->tokens->revoke($token);
        }
    }
}
