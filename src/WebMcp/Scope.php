<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\WebMcp;

use Aithos\Agentenblick\Capture\SalesChannelResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Reuse the storefront's session cookie; never issue an analytics cookie. */
final class Scope
{
    public const ATTRIBUTE = '_agentenblick_webmcp_native_scope';

    /** @param array<string, mixed> $sessionOptions */
    public function __construct(private readonly Settings $settings, private readonly SalesChannelResolver $salesChannels, private readonly array $sessionOptions = ['name' => 'session-'])
    {
    }

    public function prepare(Request $request, ?Response $response = null): bool
    {
        $this->salesChannels->resolve($request);
        $channel = $request->attributes->get('sw-sales-channel-id');
        $domain = $request->attributes->get('sw-domain-id');
        if (!is_string($channel) || !is_string($domain) || preg_match('/^[a-f0-9]{32}$/D', $domain) !== 1 || !$this->settings->enabled($channel)) {
            return false;
        }
        // Only the configured native cookie qualifies. A different plugin's
        // session-consent=1 cookie must never merge unrelated visitors.
        $name = $request->hasSession(true) ? $request->getSession()->getName() : ($this->sessionOptions['name'] ?? null);
        if (!is_string($name) || $name === '') {
            return false;
        }
        $value = $request->cookies->get($name);
        // The first page (or native session rotation) can offer the actual cookie.
        // Reading the response outside HttpCache does not create a new cookie.
        if ($response !== null) {
            foreach ($response->headers->getCookies() as $cookie) {
                if ($cookie->getName() === $name) {
                    $value = $cookie->isCleared() ? null : $cookie->getValue();
                    break;
                }
            }
        }
        if (!is_string($value) || $value === '' || strlen($value) > 256) {
            return false;
        }
        $identity = $name . "\0" . $value;
        $request->attributes->set(self::ATTRIBUTE, 'native:' . $identity . "\0" . $domain);
        return true;
    }

    public static function sessionKey(Request $request, string $key): ?string
    {
        $identity = $request->attributes->get(self::ATTRIBUTE);
        $channel = $request->attributes->get('sw-sales-channel-id');
        if (!is_string($identity) || $identity === '' || !is_string($channel) || preg_match('/^[a-f0-9]{32}$/D', $channel) !== 1) {
            return null;
        }
        return hash_hmac('sha256', "session\0webmcp:" . $identity . "\0" . $channel, $key);
    }
}
