<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Verification\SafeFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class SafeFetcherTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function blockedAddresses(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'RFC1918 10' => ['10.0.0.1'];
        yield 'RFC1918 172' => ['172.16.0.1'];
        yield 'RFC1918 192' => ['192.168.0.1'];
        yield 'metadata link local' => ['169.254.169.254'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'IPv6 local' => ['fc00::1'];
        yield 'IPv6 link local' => ['fe80::1'];
        yield 'IPv4 mapped' => ['::ffff:127.0.0.1'];
        yield 'IPv4 compatible' => ['::127.0.0.1'];
        yield '6to4 loopback' => ['2002:7f00:1::'];
        yield '6to4 private' => ['2002:a00:1::'];
        yield 'Teredo' => ['2001::1'];
        yield 'NAT64 well known' => ['64:ff9b::7f00:1'];
        yield 'NAT64 local use' => ['64:ff9b:1::a00:1'];
    }

    #[DataProvider('blockedAddresses')]
    public function testPrivateAndTransitionAddressesCannotYieldAResponse(string $address): void
    {
        $client = $this->transportAt($address);
        $host = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $this->expectException(TransportExceptionInterface::class);
        (new SafeFetcher($client))->json('https://' . $host . '/directory');
    }

    #[DataProvider('blockedAddresses')]
    public function testConnectedAddressIsCheckedEvenWhenInitialTargetIsPublic(string $address): void
    {
        $this->expectException(TransportExceptionInterface::class);
        (new SafeFetcher($this->transportAt($address)))->json('https://93.184.216.34/directory');
    }

    public function testPublicIpv4AndIpv6CanBeFetchedWithTheProductionLimits(): void
    {
        foreach (['93.184.216.34', '2606:4700:4700::1111'] as $address) {
            $host = str_contains($address, ':') ? '[' . $address . ']' : $address;
            self::assertSame(['keys' => []], (new SafeFetcher($this->transportAt($address)))->json('https://' . $host . '/directory'));
        }
    }

    public function testRedirectToAPrivateAddressIsNeverFollowed(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            ++$calls;
            self::assertSame(0, $options['max_redirects']);
            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://[64:ff9b::7f00:1]/secret']]);
        });
        try {
            (new SafeFetcher($client))->json('https://93.184.216.34/directory');
            self::fail('A redirect must not produce a trusted directory.');
        } catch (\RuntimeException $error) {
            self::assertSame('source_unavailable', $error->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testInvalidProductionUrlsAreRejectedBeforeTransport(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('Invalid source must not reach the HTTP transport.');
        });
        foreach (['http://93.184.216.34/directory', 'https://93.184.216.34:8443/directory', 'https://user:secret@93.184.216.34/directory', 'https://93.184.216.34/directory#fragment'] as $url) {
            try {
                (new SafeFetcher($client))->json($url);
                self::fail('Expected invalid_source_url.');
            } catch (\UnexpectedValueException $error) {
                self::assertSame('invalid_source_url', $error->getMessage());
            }
        }
    }

    private function transportAt(string $address): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use ($address): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(2.0, $options['timeout']);
            self::assertSame(2.0, $options['max_duration']);
            self::assertFalse($options['buffer']);
            // Model the actual connected IP, including a changed DNS answer.
            // This invokes the real Symfony protection on every supported version.
            $options['on_progress'](0, 0, ['primary_ip' => $address, 'url' => $url]);
            return new MockResponse('{"keys":[]}', ['primary_ip' => $address]);
        });
    }
}
