<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Capture\Minimizer;
use Aithos\Agentenblick\Capture\ObservationScope;
use Aithos\Agentenblick\Capture\SalesChannelResolver;
use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\WebMcp\Declaration;
use Aithos\Agentenblick\WebMcp\Scope;
use Aithos\Agentenblick\WebMcp\Settings;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class WebMcpTest extends TestCase
{
    public function testOnlyBoundedExplicitStatementsAreAccepted(): void
    {
        foreach (Declaration::SERVICES as $service) {
            foreach (Declaration::INTENTS as $intent) {
                $request = $this->post(json_encode(['service' => $service, 'intent' => $intent], JSON_THROW_ON_ERROR));
                $declaration = Declaration::fromRequest($request);
                self::assertSame(['status' => 'declared', 'service' => $service, 'intent' => $intent, 'evidence' => 'declared_unverified'], $declaration->response());
            }
        }
        self::assertFalse((new ObservationScope())->accepts($this->post('{}')));
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function invalid(): iterable
    {
        yield 'prompt not accepted' => ['{"service":"codex","intent":"product_search","prompt":"private"}', []];
        yield 'free text service' => ['{"service":"person@example.test","intent":"product_search"}', []];
        yield 'free text intent' => ['{"service":"codex","intent":"buy a secret gift"}', []];
        yield 'wrong type' => ['{"service":[],"intent":"other"}', []];
        yield 'empty' => ['{}', []];
        yield 'malformed' => ['{', []];
        yield 'oversized' => [str_repeat(' ', 257), []];
        yield 'cross origin' => ['{"service":"codex","intent":"other"}', ['Origin' => 'https://attacker.test']];
        yield 'null origin' => ['{"service":"codex","intent":"other"}', ['Origin' => 'null']];
        yield 'wrong content type' => ['{"service":"codex","intent":"other"}', ['Content-Type' => 'text/plain']];
        yield 'cross site metadata' => ['{"service":"codex","intent":"other"}', ['Sec-Fetch-Site' => 'cross-site']];
        yield 'missing xhr' => ['{"service":"codex","intent":"other"}', ['X-Requested-With' => '']];
    }

    #[DataProvider('invalid')]
    public function testUnsafePayloadsAndOriginsAreRejected(string $body, array $headers): void
    {
        $request = $this->post($body);
        $request->headers->add($headers);
        $this->expectException(\InvalidArgumentException::class);
        Declaration::fromRequest($request);
    }

    public function testFirstNativeCookieScopesInitialPageWithoutCreatingAnAnalyticsCookie(): void
    {
        $scope = $this->scope(true);
        $request = $this->page();
        $response = new Response();
        self::assertFalse($scope->prepare($request, $response));
        self::assertSame([], $response->headers->getCookies());
        $response->headers->setCookie(Cookie::create('session-', 'private-native-cookie'));
        self::assertTrue($scope->prepare($request, $response));
        $next = $this->page(['session-' => 'private-native-cookie']);
        self::assertTrue($scope->prepare($next));
        self::assertSame(Scope::sessionKey($request, str_repeat('a', 32)), Scope::sessionKey($next, str_repeat('a', 32)));
        self::assertCount(1, $response->headers->getCookies());
        self::assertSame('session-', $response->headers->getCookies()[0]->getName());
    }

    public function testUnrelatedSessionPrefixedCookieCannotBindVisitors(): void
    {
        $scope = $this->scope(true);
        $request = $this->page(['session-consent' => '1']);
        self::assertFalse($scope->prepare($request));
        self::assertNull(Scope::sessionKey($request, str_repeat('a', 32)));
        $request->cookies->set('session-', 'actual-native-cookie');
        self::assertTrue($scope->prepare($request));
        $other = $this->page(['session-' => 'actual-native-cookie', 'session-consent' => 'different']);
        $scope->prepare($other);
        self::assertSame(Scope::sessionKey($request, str_repeat('a', 32)), Scope::sessionKey($other, str_repeat('a', 32)));
    }

    public function testConfiguredOrAlreadyAttachedNativeSessionNameIsRespected(): void
    {
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getBool')->willReturn(true);
        $scope = new Scope(new Settings($config), new SalesChannelResolver($this->createStub(Connection::class)), ['name' => 'custom-shop-session']);
        $request = $this->page(['session-' => 'ignored-default', 'custom-shop-session' => 'native-custom-value']);
        self::assertTrue($scope->prepare($request));
        $key = Scope::sessionKey($request, str_repeat('a', 32));
        $native = $this->createMock(SessionInterface::class);
        $native->method('getName')->willReturn('custom-shop-session');
        $native->expects(self::never())->method('start');
        $next = $this->page(['custom-shop-session' => 'native-custom-value']);
        $next->setSession($native);
        self::assertTrue($this->scope(true)->prepare($next));
        self::assertSame($key, Scope::sessionKey($next, str_repeat('a', 32)));
    }

    public function testScopeIsOptInAndBoundToDomainChannelCookieAndDailyKey(): void
    {
        $request = $this->page(['session-' => 'private-native-cookie']);
        self::assertFalse($this->scope(false)->prepare($request));
        self::assertNull(Scope::sessionKey($request, str_repeat('a', 32)));
        $scope = $this->scope(true);
        $scope->prepare($request);
        $key = Scope::sessionKey($request, str_repeat('a', 32));
        self::assertNotSame($key, Scope::sessionKey($request, str_repeat('b', 32)));
        $other = $this->page(['session-' => 'other-native-cookie']);
        $scope->prepare($other);
        self::assertNotSame($key, Scope::sessionKey($other, str_repeat('a', 32)));
        $request->attributes->set('sw-domain-id', str_repeat('d', 32));
        $scope->prepare($request);
        self::assertNotSame($key, Scope::sessionKey($request, str_repeat('a', 32)));
        $request->attributes->set('sw-sales-channel-id', str_repeat('e', 32));
        $scope->prepare($request);
        self::assertNotSame($key, Scope::sessionKey($request, str_repeat('a', 32)));
    }

    public function testScopedHumansStayUnidentifiedAndDeclarationsRetainNoRawUa(): void
    {
        $request = $this->page(['session-' => 'private-native-cookie']);
        $request->headers->set('User-Agent', 'Mozilla/5.0 Chrome/130.0 private-browser');
        $this->scope(true)->prepare($request);
        $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $human = (new Minimizer())->minimize($request, new Classification(), str_repeat('a', 32), $at, 200);
        self::assertSame('human_or_unknown', $human['kind']);
        self::assertSame('', $human['browser_family']);
        self::assertSame('', $human['user_agent']);
        self::assertSame('exact', $human['linkage']);
        self::assertSame(1, $human['webmcp_scope']);
        $agent = (new Minimizer())->minimize($request, (new Declaration('codex', 'product_search'))->classification(), str_repeat('a', 32), $at, 200);
        self::assertSame($human['session_key'], $agent['session_key']);
        self::assertSame('', $agent['user_agent']);
        self::assertSame('declared_unverified', $agent['evidence']);
        // Database rows include a binary domain ID; inspect every field without
        // requiring those bytes to be UTF-8 or discarding them from the check.
        $serialized = serialize([$human, $agent]);
        foreach (['private-native-cookie', 'private-browser', 'secret-query'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized);
        }
    }

    private function scope(bool $enabled): Scope
    {
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getBool')->willReturn($enabled);
        return new Scope(new Settings($config), new SalesChannelResolver($this->createStub(Connection::class)));
    }

    /** @param array<string, string> $cookies */
    private function page(array $cookies = []): Request
    {
        $request = Request::create('https://shop.test/?secret-query=private', cookies: $cookies);
        $request->attributes->set('sw-sales-channel-id', str_repeat('a', 32));
        $request->attributes->set('sw-domain-id', str_repeat('b', 32));
        return $request;
    }

    private function post(string $body): Request
    {
        $request = Request::create('https://shop.test/agentenblick/webmcp/declare', 'POST', content: $body);
        $request->headers->add(['Content-Type' => 'application/json', 'Origin' => 'https://shop.test', 'X-Requested-With' => 'XMLHttpRequest', 'Sec-Fetch-Site' => 'same-origin']);
        return $request;
    }
}
