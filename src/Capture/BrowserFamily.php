<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Capture;

/** Human counters use only these families; no input text is returned or persisted. */
final class BrowserFamily
{
    /** @var list<string> */
    public const FAMILIES = ['chrome', 'edge', 'firefox', 'safari', 'opera', 'samsung_internet', 'other'];

    public static function fromUserAgent(string $userAgent): string
    {
        // More specific Chromium derivatives must precede their compatibility tokens.
        $userAgent = substr($userAgent, 0, 2048);
        foreach ([
            'edge' => '~\b(?:Edge|Edg|EdgA|EdgiOS)/~i',
            'opera' => '~\b(?:Opera|OPR|OPiOS)/~i',
            'samsung_internet' => '~\bSamsungBrowser/~i',
            'firefox' => '~\b(?:Firefox|FxiOS)/~i',
            'chrome' => '~\b(?:Chrome|Chromium|CriOS|HeadlessChrome)/~i',
            'safari' => '~\bSafari/~i',
        ] as $family => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $family;
            }
        }

        return 'other';
    }
}
