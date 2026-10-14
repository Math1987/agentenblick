<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Verification\Catalogue;
use Aithos\Agentenblick\Verification\RangeRefresher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

final class IpAndProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], -1);
    }

    public function testOnlyDeclaredProxyCanSelectTheForwardedClient(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        $trusted = Request::create('https://shop.test/', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '172.29.240.42']);
        $untrusted = Request::create('https://shop.test/', server: ['REMOTE_ADDR' => '198.51.100.20', 'HTTP_X_FORWARDED_FOR' => '172.29.240.42', 'HTTP_CF_CONNECTING_IP' => '172.29.240.42']);
        self::assertSame('172.29.240.42', $trusted->getClientIp());
        self::assertSame('198.51.100.20', $untrusted->getClientIp());
        $catalogue = new Catalogue(__DIR__ . '/../fixtures/catalog.json', 'test');
        $agent = $catalogue->match('ExampleTestAgent/1.0');
        self::assertTrue(IpUtils::checkIp($trusted->getClientIp(), $agent['ranges']));
        self::assertFalse(IpUtils::checkIp($untrusted->getClientIp(), $agent['ranges']));
    }

    public function testLargeRangeChangesRequireConfirmation(): void
    {
        $old = ['1.1.1.0/24', '8.8.8.0/24', '9.9.9.0/24', '208.67.222.0/24'];
        self::assertFalse(RangeRefresher::needsConfirmation($old, $old));
        self::assertFalse(RangeRefresher::needsConfirmation($old, array_slice($old, 0, 3)));
        self::assertTrue(RangeRefresher::needsConfirmation($old, array_slice($old, 0, 2)));
    }

    public function testDownloadedPrivateRangesAreRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        RangeRefresher::extract(['prefixes' => [['ipv4Prefix' => '127.0.0.0/8']]]);
    }
}
