<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit\Health;

use Aithos\Agentenblick\Health\Domains;
use PHPUnit\Framework\TestCase;

final class DomainsTest extends TestCase
{
    public function testOnlyConfiguredPublicHttpsShapesCanReachTheNetworkBoundary(): void
    {
        foreach (['http://shop.example', 'https://localhost', 'https://127.0.0.1', 'https://[::1]', 'https://shop.example:8443', 'https://user:secret@shop.example', 'https://shop.example/?secret=x', 'https://shop.example/#fragment', "https://shop.example/\npath", 'https://shop.example\\@localhost'] as $url) {
            self::assertNull(Domains::safeUrl($url), $url);
        }
        self::assertSame('https://shop.example/de', Domains::safeUrl('https://SHOP.example:443/de/'));
        self::assertSame('https://shop.example', Domains::origin('https://shop.example/de'));
    }
}
