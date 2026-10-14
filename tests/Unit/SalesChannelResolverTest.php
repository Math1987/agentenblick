<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Capture\SalesChannelResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SalesChannelResolverTest extends TestCase
{
    public function testDomainMatchingUsesOriginAndLongestBoundaryPrefix(): void
    {
        $domains = [['url' => 'https://shop.test', 'id' => 'root'], ['url' => 'https://shop.test/de', 'id' => 'de']];
        self::assertSame('de', SalesChannelResolver::match(Request::create('https://shop.test/de/product?email=secret'), $domains));
        self::assertSame('root', SalesChannelResolver::match(Request::create('https://shop.test/deceptive'), $domains));
        self::assertNull(SalesChannelResolver::match(Request::create('https://other.test/de'), $domains));
        self::assertNull(SalesChannelResolver::match(Request::create('http://shop.test/de'), $domains));
        self::assertNull(SalesChannelResolver::match(Request::create('https://shop.test/'), [...$domains, ['url' => 'https://shop.test', 'id' => 'other']]));
    }
}
