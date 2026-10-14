<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Symfony\Component\HttpFoundation\Request;

final class WebBotAuth
{
    /**
     * @param callable(string): array<string, mixed> $directory Returns the catalogue origin's JWKS or throws.
     * @param (callable(string): ?string)|null $knownOrigin Read-only keyid lookup for requests without Signature-Agent.
     */
    public function verify(Request $request, Catalogue $catalogue, callable $directory, int $now, ?callable $knownOrigin = null): Classification
    {
        $http = new HttpSignature();
        $reason = 'bad_signature';
        $operator = 'unknown';
        $agentId = 'unknown';
        $pending = null;
        try {
            $signatures = $http->parse(($request->headers->get('Signature-Input') ?? ''), ($request->headers->get('Signature') ?? ''));
            foreach ($signatures as $label => $candidate) {
                try {
                    $input = $candidate['input'];
                    if ($input->parameterByKey('tag') !== 'web-bot-auth') {
                        continue;
                    }
                    $agentMember = $label;
                    foreach ($input as $component) {
                        if ($component->value() === 'signature-agent' && is_string($component->parameterByKey('key'))) {
                            $agentMember = $component->parameterByKey('key');
                        }
                    }
                    $keyId = $input->parameterByKey('keyid');
                    if (!is_string($keyId) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $keyId)) {
                        $reason = 'unknown_key';
                        continue;
                    }
                    $hasSignatureAgent = $request->headers->has('Signature-Agent');
                    $origin = $hasSignatureAgent
                        ? $http->agentOrigin(($request->headers->get('Signature-Agent') ?? ''), $agentMember)
                        : ($knownOrigin === null ? null : $knownOrigin($keyId));
                    if (!is_string($origin)) {
                        $reason = 'unknown_key';
                        continue;
                    }
                    $agent = $catalogue->origin($origin);
                    if ($agent === null) {
                        $reason = 'unknown_key';
                        continue;
                    }
                    $operator = $agent['operator'];
                    $agentId = $agent['id'];
                    $created = $input->parameterByKey('created');
                    $expires = $input->parameterByKey('expires');
                    if (!is_int($created) || !is_int($expires) || $expires < $created || $created > $now + 60 || $expires < $now - 60) {
                        $reason = 'expired';
                        continue;
                    }
                    $names = [];
                    $agentCovered = false;
                    foreach ($input as $component) {
                        $names[] = $component->value();
                        if ($component->value() === 'signature-agent' && in_array($component->parameterByKey('key'), [null, $agentMember], true)) {
                            $agentCovered = true;
                        }
                    }
                    if ((!in_array('@authority', $names, true) && !in_array('@target-uri', $names, true)) || ($hasSignatureAgent && !$agentCovered)) {
                        $reason = 'bad_signature';
                        continue;
                    }
                    $base = $http->base($request, $input);
                    try {
                        $document = $directory($origin);
                    } catch (\Throwable) {
                        $reason = 'directory_unreachable';
                        $pending ??= new Classification($operator, $agentId, Kind::BrowsingAgent, Evidence::Declared, $reason, null, $label);
                        continue;
                    }
                    $reason = 'unknown_key';
                    foreach ($document['keys'] ?? [] as $key) {
                        try {
                            if (!is_array($key) || !in_array($key['kty'] ?? null, ['OKP', 'RSA'], true)) {
                                continue;
                            }
                            if (!hash_equals(Jwk::thumbprint($key), $keyId) || (($key['kid'] ?? $keyId) !== $keyId)) {
                                continue;
                            }
                            $algorithm = $input->parameterByKey('alg') ?? ($key['kty'] === 'OKP' ? 'ed25519' : 'rsa-pss-sha512');
                            $reason = 'bad_signature';
                            if (is_string($algorithm) && Jwk::verify($key, $algorithm, $base, $candidate['signature'])) {
                                return new Classification($operator, $agentId, Kind::BrowsingAgent, Evidence::Signature, 'valid_web_bot_auth', $keyId, $label);
                            }
                        } catch (\Throwable) {
                            // One unusable JWK must not hide another matching key.
                            continue;
                        }
                    }
                } catch (\Throwable) {
                    $reason = 'bad_signature';
                    continue;
                }
            }
        } catch (\Throwable) {
            $reason = 'bad_signature';
        }
        return $pending ?? new Classification($operator, $agentId, Kind::BrowsingAgent, Evidence::Declared, $reason);
    }
}
