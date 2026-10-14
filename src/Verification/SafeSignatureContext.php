<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Aithos\Agentenblick\Domain\Classification;
use Bakame\Http\StructuredFields\Dictionary;
use Bakame\Http\StructuredFields\Item;
use Symfony\Component\HttpFoundation\Request;

/** An intentionally small replay format. It never serializes a Request. */
final class SafeSignatureContext
{
    private const PATHS = ['/', '/robots.txt', '/llms.txt', '/llms-full.txt', '/.well-known/ucp'];

    /** @return array<string, mixed>|null */
    public function capture(Request $request, Catalogue $catalogue, Classification $classification): ?array
    {
        try {
            // An origin inferred from a cache is not replayed after that cache changes.
            // Immediate verification can still succeed using known public keys.
            if (!$request->headers->has('Signature-Agent')) {
                return null;
            }
            $label = $classification->signatureLabel;
            if ($label === null || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $label)) {
                return null;
            }
            $http = new HttpSignature();
            $candidate = $http->parse(($request->headers->get('Signature-Input') ?? ''), ($request->headers->get('Signature') ?? ''))[$label] ?? null;
            if ($candidate === null) {
                return null;
            }
            $input = $candidate['input'];
            // Unknown parameters (including opaque nonces) could carry forbidden data.
            // Their signature may still verify immediately; it simply cannot be retained.
            if (!$input->parameters()->allowedKeys(['created', 'expires', 'keyid', 'alg', 'tag'])
                || !is_int($input->parameterByKey('created')) || !is_int($input->parameterByKey('expires'))
                || $input->parameterByKey('tag') !== 'web-bot-auth'
                || !is_string($input->parameterByKey('keyid'))
                || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $input->parameterByKey('keyid'))
                || !in_array($input->parameterByKey('alg'), [null, 'ed25519', 'rsa-pss-sha512'], true)) {
                return null;
            }
            $member = $label;
            $retainPath = false;
            foreach ($input as $component) {
                $name = $component->value();
                if (!in_array($name, ['@authority', '@method', '@scheme', '@target-uri', '@path', '@request-target', '@query', 'signature-agent'], true)) {
                    return null;
                }
                if (!$component->parameters()->allowedKeys(['sf', 'key'])) {
                    return null;
                }
                if ($name === 'signature-agent' && $component->parameterByKey('key') !== null) {
                    $member = $component->parameterByKey('key');
                    if (!is_string($member) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $member)) {
                        return null;
                    }
                }
                if (in_array($name, ['@target-uri', '@path', '@request-target', '@query'], true)) {
                    $retainPath = true;
                }
            }
            $target = $request->getRequestUri();
            if ($retainPath && (str_contains($target, '?') || !in_array($target, self::PATHS, true))) {
                return null;
            }
            $origin = $http->agentOrigin(($request->headers->get('Signature-Agent') ?? ''), $member);
            if ($catalogue->origin($origin) === null) {
                return null;
            }
            $originalAgent = ($request->headers->get('Signature-Agent') ?? '');
            $agentItem = str_starts_with(ltrim($originalAgent), '"')
                ? Item::fromRfc8941($originalAgent)
                : Dictionary::fromRfc8941($originalAgent)->getByKey($member);
            $agentHeader = str_starts_with(ltrim($originalAgent), '"')
                ? $agentItem->toRfc8941()
                : $member . '=' . $agentItem->toRfc8941();
            $authority = $request->getHttpHost();
            if (strlen($authority) > 253 || !preg_match('/^[a-z0-9.\[\]:-]+$/Di', $authority)
                || !in_array($request->getMethod(), ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                return null;
            }
            $context = [
                'version' => 1,
                'uri' => $request->getSchemeAndHttpHost() . ($retainPath ? $target : '/'),
                'method' => $request->getMethod(),
                'headers' => [
                    'Signature-Input' => $label . '=' . $input->toRfc8941(),
                    'Signature' => $label . '=:' . base64_encode($candidate['signature']) . ':',
                    'Signature-Agent' => $agentHeader,
                ],
            ];
            // This equality also rejects unsigned dictionary members if the signature
            // covers the entire field. Nothing is retained merely because it looks safe.
            if ($http->base($request, $input) !== $http->base($this->restore($context), $input)) {
                return null;
            }
            return $context;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $context */
    public function restore(array $context): Request
    {
        if (($context['version'] ?? null) !== 1 || !is_string($context['uri'] ?? null)
            || !is_string($context['method'] ?? null) || !is_array($context['headers'] ?? null)) {
            throw new \UnexpectedValueException('invalid_replay_context');
        }
        $request = Request::create($context['uri'], $context['method']);
        $request->headers->add($context['headers']);
        return $request;
    }
}
