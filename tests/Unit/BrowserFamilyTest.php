<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Capture\BrowserFamily;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowserFamilyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function browsers(): iterable
    {
        yield 'Chrome compatibility Safari' => ['Mozilla/5.0 AppleWebKit/537.36 Chrome/129.0.0.0 Safari/537.36', 'chrome'];
        yield 'Chromium' => ['Mozilla/5.0 Chromium/129.0.0.0 Safari/537.36', 'chrome'];
        yield 'Chrome iOS' => ['Mozilla/5.0 AppleWebKit/605.1.15 CriOS/129.0.0.0 Mobile/15E148 Safari/604.1', 'chrome'];
        yield 'Firefox desktop' => ['Mozilla/5.0 Gecko/20100101 Firefox/130.0', 'firefox'];
        yield 'Firefox iOS' => ['Mozilla/5.0 FxiOS/130.0 Mobile/15E148 Safari/605.1.15', 'firefox'];
        yield 'Edge Chromium' => ['Mozilla/5.0 Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0', 'edge'];
        yield 'Edge legacy' => ['Mozilla/5.0 Chrome/64.0.0.0 Safari/537.36 Edge/18.0', 'edge'];
        yield 'Edge Android' => ['Mozilla/5.0 Chrome/129.0.0.0 Mobile Safari/537.36 EdgA/129.0.0.0', 'edge'];
        yield 'Edge iOS' => ['Mozilla/5.0 EdgiOS/129.0.0.0 Mobile/15E148 Safari/605.1.15', 'edge'];
        yield 'Opera Chromium' => ['Mozilla/5.0 Chrome/129.0.0.0 Safari/537.36 OPR/115.0.0.0', 'opera'];
        yield 'Opera legacy' => ['Opera/9.80 Presto/2.12.388 Version/12.18', 'opera'];
        yield 'Opera iOS' => ['Mozilla/5.0 OPiOS/4.1 Mobile/15E148 Safari/605.1.15', 'opera'];
        yield 'Samsung Chromium' => ['Mozilla/5.0 SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'samsung_internet'];
        yield 'Safari desktop' => ['Mozilla/5.0 AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15', 'safari'];
        yield 'Safari iOS' => ['Mozilla/5.0 AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', 'safari'];
        yield 'Unrecognised' => ['PersonalBrowser/person@example.test', 'other'];
        yield 'Empty' => ['', 'other'];
    }

    #[DataProvider('browsers')]
    public function testReturnsOnlyTheBrowserFamily(string $userAgent, string $expected): void
    {
        self::assertSame($expected, BrowserFamily::fromUserAgent($userAgent));
        self::assertContains(BrowserFamily::fromUserAgent($userAgent), BrowserFamily::FAMILIES);
    }

    public function testPersonalValuesNeverBecomeAFamily(): void
    {
        foreach (['person@example.test', '203.0.113.42', 'context-token-secret', str_repeat('a', 10000), "\xff\xfe\0"] as $userAgent) {
            self::assertSame('other', BrowserFamily::fromUserAgent($userAgent));
        }
        self::assertSame('chrome', BrowserFamily::fromUserAgent('Chrome/129.0 person@example.test 203.0.113.42'));
        self::assertSame('other', BrowserFamily::fromUserAgent('NotChrome/129.0'));
    }
}
