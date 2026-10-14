<?php

declare(strict_types=1);

/** Test harness only. Never registered by the plugin or distributed as runtime code. */
namespace Agentenblick\Acceptance;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HealthHttpFixture
{
    private const CONFIG = '/tmp/agentenblick-health-fixture.json';

    /** @return array{host: string, channel: string, domain: string, log: string} */
    public static function config(): array
    {
        if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'test') {
            throw new \LogicException('Health fixture requires APP_ENV=test.');
        }
        $config = json_decode((string) file_get_contents(self::CONFIG), true, 8, JSON_THROW_ON_ERROR);
        if (($config['host'] ?? '') !== 'agentenblick-health-fixture.example' || !preg_match('/^[a-f0-9]{32}$/D', $config['channel'] ?? '') || !preg_match('/^[a-f0-9]{32}$/D', $config['domain'] ?? '') || !preg_match('#^/tmp/agentenblick-health-[a-f0-9]{12}\.jsonl$#D', $config['log'] ?? '')) {
            throw new \LogicException('Invalid isolated fixture configuration.');
        }
        return $config;
    }

    public static function client(): HttpClientInterface
    {
        return new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $config = self::config();
            $parts = parse_url($url);
            $path = $parts['path'] ?? '/';
            if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $config['host'] || isset($parts['query']) || isset($parts['port']) || !in_array($method, ['GET', 'OPTIONS'], true) || !in_array($path, ['/', '/robots.txt', '/llms.txt', '/llms-full.txt', '/agents.md', '/AGENTS.md', '/.well-known/ucp', '/.well-known/agent-card.json', '/store-api/_mcp'], true)) {
                throw new \LogicException('The test mapper refuses every other URL.');
            }
            // This reports a TEST IP to the unchanged public-network wrappers.
            // Real HTTP is exercised below; public DNS/TLS are NOT tested here.
            ($options['on_progress'] ?? static function (): void {})(0, 0, ['primary_ip' => '93.184.216.34', 'url' => $url]);
            $headers = [];
            foreach ($options['normalized_headers'] ?? [] as $name => $values) {
                if (in_array(strtolower($name), ['user-agent', 'x-agentenblick-health-probe', 'accept'], true)) {
                    foreach ($values as $value) {
                        $headers[] = $value;
                    }
                }
            }
            $headers[] = 'Host: ' . $config['host'];
            $response = HttpClient::create()->request($method, 'http://127.0.0.1' . $path, ['headers' => $headers, 'max_redirects' => 0, 'timeout' => 2.0, 'max_duration' => 2.0]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $mime = $response->getHeaders(false)['content-type'][0] ?? 'application/octet-stream';
            file_put_contents($config['log'], json_encode(['method' => $method, 'path' => $path, 'status' => $status], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
            return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['content-type: ' . $mime], 'primary_ip' => '93.184.216.34']);
        });
    }
}

final class HealthHttpFixtureSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['respond', 9000]];
    }

    public function respond(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->getHost() !== 'agentenblick-health-fixture.example') {
            return;
        }
        $config = HealthHttpFixture::config();
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $agent = $request->headers->get('User-Agent') ?? '';
        $status = match ($path) {
            '/allowed403' => 403,
            '/allowed429' => 429,
            '/.well-known/ucp', '/store-api/_mcp' => 500,
            '/agents.md' => 404,
            '/' => match ($agent) { 'GPTBot' => 403, 'ClaudeBot' => 429, default => 200 },
            default => 200,
        };
        $body = $path === '/robots.txt'
            ? "User-agent: GPTBot\nAllow: /\nDisallow: /blocked\n\nUser-agent: ClaudeBot\nAllow: /\n\nUser-agent: ChatGPT-User\nAllow: /\n\nUser-agent: Google-Extended\nDisallow: /\n"
            : 'Synthetic health fixture only';
        $mime = in_array($path, ['/.well-known/ucp', '/.well-known/agent-card.json', '/store-api/_mcp'], true) ? 'application/json' : 'text/plain';
        $request->attributes->set('sw-sales-channel-id', $config['channel']);
        $request->attributes->set('sw-domain-id', $config['domain']);
        $request->attributes->set('_route', $path === '/search' ? 'frontend.search.page' : 'frontend.navigation.page');
        $response = new Response($body, $status, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store']);
        $event->setResponse($response);
    }
}
