<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Capture;

use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Journey\Signals;
use Aithos\Agentenblick\Health\Observation;
use Symfony\Component\HttpFoundation\Request;

final class Minimizer
{
    /** @param array<string, mixed>|null $signatureContext
     * @return array<string, mixed>
     */
    public function minimize(Request $request, Classification $classification, string $key, \DateTimeImmutable $at, int $status, ?array $signatureContext = null): array
    {
        $hmac = static fn (string $purpose, string $value): string => hash_hmac('sha256', $purpose . "\0" . $value, $key);
        $ip = $request->getClientIp() ?? '';
        $ipHash = $hmac('ip', $ip);
        $userAgent = substr(($request->headers->get('User-Agent') ?? ''), 0, 1024);
        $context = $request->headers->get('sw-context-token');
        $cookie = null;
        foreach ($request->cookies->all() as $name => $value) {
            if (str_starts_with((string) $name, 'session-') && is_string($value)) {
                $cookie = $value;
                break;
            }
        }
        if ($classification->keyId !== null && $classification->evidence === Evidence::Signature) {
            $identity = 'key:' . $classification->keyId;
            $linkage = 'probabilistic';
        } elseif (is_string($context) && $context !== '') {
            $identity = 'context:' . $context;
            $linkage = 'exact';
        } elseif (is_string($cookie) && $cookie !== '') {
            $identity = 'cookie:' . $cookie;
            $linkage = 'exact';
        } else {
            $identity = 'fingerprint:' . $ipHash . "\0" . $userAgent . "\0" . $request->headers->get('Accept-Language', '');
            $linkage = 'probabilistic';
        }
        $channel = $request->attributes->get('sw-sales-channel-id', 'unassigned');
        $channel = is_string($channel) && preg_match('/^[a-f0-9]{32}$/', $channel) ? $channel : 'unassigned';
        $domain = $request->attributes->get('sw-domain-id');
        $domain = $classification->isNonHuman() && is_string($domain) && preg_match('/^[a-f0-9]{32}$/D', $domain) ? hex2bin($domain) : null;
        $resource = $classification->isNonHuman() ? Observation::resource($request) : '';
        $completed = $request->attributes->get('_agentenblick_completed_at');
        $completed = in_array($resource, ['llms', 'llms_full'], true) && $status >= 200 && $status < 300 && is_string($completed) ? $completed : null;
        $sessionKey = $hmac('session', $identity . "\0" . $channel);
        $row = [
            'observed_at' => $at->format('Y-m-d H:i:s.v'), 'day' => $at->format('Y-m-d'),
            'sales_channel_id' => $channel, 'session_key' => $sessionKey, 'linkage' => $linkage,
            'operator' => $classification->operator, 'agent' => $classification->agent,
            'kind' => $classification->kind->value, 'evidence' => $classification->evidence->value,
            'reason' => $classification->reason, 'status' => $status,
            'route' => (new ObservationScope())->route($request),
            'method' => substr($request->getMethod(), 0, 10), 'ip_hmac' => $ipHash,
            'user_agent' => $classification->isNonHuman() ? $userAgent : '',
            'browser_family' => $classification->isNonHuman() ? '' : BrowserFamily::fromUserAgent($userAgent),
            'domain_id' => $domain,
            'discovery_resource' => $resource, 'discovery_completed_at' => $completed,
            'network_prefix' => null, 'signature_input' => null,
            'signature_value' => null, 'signature_agent' => null, 'key_id' => $classification->keyId,
            'pending_context' => null, 'verify_after' => null, 'verify_attempts' => 0,
        ];
        // Unvalidated header input may contain personal data: only preserve verified signature metadata.
        if ($classification->evidence === Evidence::Signature && $signatureContext !== null) {
            $row['signature_input'] = $signatureContext['headers']['Signature-Input'];
            $row['signature_value'] = $signatureContext['headers']['Signature'];
            $row['signature_agent'] = $signatureContext['headers']['Signature-Agent'];
        }
        if ($classification->reason === 'directory_unreachable') {
            if ($signatureContext === null) {
                $row['reason'] = 'directory_unreachable_privacy';
            } else {
                $row['pending_context'] = json_encode($signatureContext, JSON_THROW_ON_ERROR);
                $row['verify_after'] = $at->modify('+5 minutes')->format('Y-m-d H:i:s');
            }
        }
        if (in_array($classification->evidence, [Evidence::Ip, Evidence::Rdns, Evidence::Signature], true) && $ip !== '') {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                $v4 = strlen($packed) === 4;
                $prefix = substr($packed, 0, $v4 ? 3 : 6) . str_repeat("\0", $v4 ? 1 : 10);
                $row['network_prefix'] = inet_ntop($prefix) . ($v4 ? '/24' : '/48');
            }
        }
        return $row + Signals::minimized($request, $status, $classification->isNonHuman());
    }
}
