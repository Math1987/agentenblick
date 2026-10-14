<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Heuristics;

use Symfony\Component\HttpFoundation\Request;

/** All header and IP values stay in memory; only named booleans leave capture. */
final class HeuristicSignals
{
    public const FETCH_METADATA_MISSING = 1;
    public const CLIENT_HINTS_MISSING = 2;
    public const BROWSER_HEADERS_INCOHERENT = 4;
    public const TOOL_USER_AGENT = 8;
    public const WILDCARD_HTML_ACCEPT = 16;
    public const LANGUAGE_MISSING = 32;
    public const DEEP_WITHOUT_REFERER = 64;
    public const API_WITHOUT_SESSION_COOKIE = 128;
    public const HOSTING_NETWORK = 256;
    public const REQUEST_MASK = 511;

    public function __construct(private readonly HostingRanges $hosting)
    {
    }

    /** @return array{flags: int, cookiePresent: bool} */
    public function capture(Request $request, bool $isCdn = false): array
    {
        $flags = 0;
        $ua = substr($request->headers->get('User-Agent') ?? '', 0, 1024);
        $accept = trim(substr($request->headers->get('Accept') ?? '', 0, 512));
        $mode = strtolower(substr($request->headers->get('Sec-Fetch-Mode') ?? '', 0, 32));
        $dest = strtolower(substr($request->headers->get('Sec-Fetch-Dest') ?? '', 0, 32));
        $brands = substr($request->headers->get('Sec-CH-UA') ?? '', 0, 512);
        $path = $request->getPathInfo();
        $api = preg_match('~^/(?:store-api|ucp)(?:/|$)~D', $path) === 1;
        $html = !$api && ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && preg_match('~\.(?:txt|xml|json|md)(?:\.gz)?$~iD', $path) !== 1
            && !str_starts_with($path, '/.well-known/');
        $chromium = preg_match('~(?:Chrome|Chromium|Edg|HeadlessChrome)/~', $ua) === 1;
        if ($html && !$request->headers->has('Sec-Fetch-Site') && $mode === '' && $dest === '') {
            $flags |= self::FETCH_METADATA_MISSING;
        }
        // Safari and Firefox do not promise Chromium client hints.
        if ($html && $chromium && $brands === '') {
            $flags |= self::CLIENT_HINTS_MISSING;
        }
        if (($mode === 'navigate' && $dest !== '' && !in_array($dest, ['document', 'iframe', 'frame'], true))
            || (!$chromium && preg_match('~"(?:Chromium|Google Chrome|Microsoft Edge)"~i', $brands) === 1)) {
            $flags |= self::BROWSER_HEADERS_INCOHERENT;
        }
        if (preg_match('~(?:^|[\s;(])(?:HeadlessChrome|python-requests|python-urllib|curl|Go-http-client|node-fetch|axios|Playwright|Puppeteer|undici)(?:/|\b)|^node$~i', $ua) === 1) {
            $flags |= self::TOOL_USER_AGENT;
        }
        if ($html && preg_match('~^\*/\*(?:\s*;\s*q=(?:0(?:\.\d{1,3})?|1(?:\.0{1,3})?))?$~D', $accept) === 1) {
            $flags |= self::WILDCARD_HTML_ACCEPT;
        }
        if (trim($request->headers->get('Accept-Language') ?? '') === '') {
            $flags |= self::LANGUAGE_MISSING;
        }
        if ($html && substr_count(trim($path, '/'), '/') >= 1 && trim($request->headers->get('Referer') ?? '') === '') {
            $flags |= self::DEEP_WITHOUT_REFERER;
        }
        $cookiePresent = false;
        foreach ($request->cookies->all() as $name => $value) {
            if (str_starts_with((string) $name, 'session-') && is_string($value) && $value !== '') {
                $cookiePresent = true;
                break;
            }
        }
        if ($api && !$cookiePresent) {
            $flags |= self::API_WITHOUT_SESSION_COOKIE;
        }
        // Symfony alone resolves the client IP through its trusted proxy policy.
        if ($this->hosting->contains($request->getClientIp(), $isCdn)) {
            $flags |= self::HOSTING_NETWORK;
        }
        return ['flags' => $flags, 'cookiePresent' => $cookiePresent];
    }
}
