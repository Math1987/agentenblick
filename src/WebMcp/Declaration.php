<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\WebMcp;

use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Symfony\Component\HttpFoundation\Request;

final readonly class Declaration
{
    public const SERVICES = ['codex', 'chatgpt', 'claude', 'grok', 'gemini', 'other', 'unknown'];
    public const INTENTS = ['product_search', 'product_comparison', 'purchase_preparation', 'other'];

    public function __construct(public string $service, public string $intent)
    {
        if (!in_array($service, self::SERVICES, true) || !in_array($intent, self::INTENTS, true)) {
            throw new \InvalidArgumentException('invalid_declaration');
        }
    }

    public static function fromRequest(Request $request): self
    {
        if ($request->getMethod() !== 'POST'
            || strtolower(trim(explode(';', $request->headers->get('Content-Type') ?? '')[0])) !== 'application/json'
            || $request->headers->get('X-Requested-With') !== 'XMLHttpRequest'
            || $request->headers->get('Origin') !== $request->getSchemeAndHttpHost()
            || !in_array($request->headers->get('Sec-Fetch-Site', 'same-origin'), ['same-origin', 'none'], true)) {
            throw new \InvalidArgumentException('invalid_origin');
        }
        $raw = $request->getContent();
        if (strlen($raw) > 256) {
            throw new \InvalidArgumentException('invalid_declaration');
        }
        try {
            $body = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('invalid_declaration');
        }
        if (!is_array($body) || count($body) !== 2 || !is_string($body['service'] ?? null) || !is_string($body['intent'] ?? null)) {
            throw new \InvalidArgumentException('invalid_declaration');
        }
        return new self($body['service'], $body['intent']);
    }

    public function classification(): Classification
    {
        [$operator, $agent] = match ($this->service) {
            'codex' => ['openai', 'Codex'],
            'chatgpt' => ['openai', 'ChatGPT'],
            'claude' => ['anthropic', 'Claude'],
            'grok' => ['xai', 'Grok'],
            'gemini' => ['google', 'Gemini'],
            default => ['unknown', 'Voluntary browsing agent'],
        };
        return new Classification($operator, $agent, Kind::BrowsingAgent, Evidence::Declared, 'webmcp_self_declaration');
    }

    /** @return array{status: string, service: string, intent: string, evidence: string} */
    public function response(): array
    {
        return ['status' => 'declared', 'service' => $this->service, 'intent' => $this->intent, 'evidence' => Evidence::Declared->value];
    }
}
