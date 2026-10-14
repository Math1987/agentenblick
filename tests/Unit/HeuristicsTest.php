<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Heuristics\HeuristicScore;
use Aithos\Agentenblick\Heuristics\HeuristicSignals;
use Aithos\Agentenblick\Heuristics\HostingRanges;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HeuristicsTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36';

    /** @param array<string, string> $headers */
    private function request(string $path = '/detail/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', array $headers = []): Request
    {
        $request = Request::create($path, server: ['REMOTE_ADDR' => '198.51.100.77']);
        $request->headers->replace($headers + ['Host' => 'example.test']);
        return $request;
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return ['User-Agent' => self::CHROME, 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'de-DE,de;q=0.9', 'Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Sec-CH-UA' => '"Chromium";v="130", "Not-A.Brand";v="99"', 'Referer' => 'https://example.test/private-product?private-query'];
    }

    public function testToolSignalsRemainBooleansWithoutRetainingRequestData(): void
    {
        $request = $this->request(headers: ['User-Agent' => 'curl/8.7.1 private-marker', 'Accept' => '*/*']);
        $request->headers->set('Authorization', 'Bearer private-token');
        $request->cookies->set('tracking', 'private-cookie');
        $facts = (new HeuristicSignals(new HostingRanges()))->capture($request);
        self::assertTrue(($facts['flags'] & HeuristicSignals::TOOL_USER_AGENT) !== 0);
        $score = (new HeuristicScore())->evaluate($facts['flags'], ['requests' => 1]);
        self::assertTrue($score['suspected']);
        self::assertSame('1', $score['version']);
        self::assertStringNotContainsString('private', json_encode([$facts, $score], JSON_THROW_ON_ERROR));
        self::assertSame(['flags', 'cookiePresent'], array_keys($facts));
    }

    public function testMissingClientHintsLanguageAndRefererAloneDoNotMeetDefaultThreshold(): void
    {
        $headers = $this->browserHeaders();
        foreach (['Sec-CH-UA', 'Accept-Language', 'Referer', 'Sec-Fetch-Mode', 'Sec-Fetch-Site', 'Sec-Fetch-Dest'] as $key) {
            unset($headers[$key]);
        }
        $headers['Accept'] = '*/*';
        $facts = (new HeuristicSignals(new HostingRanges()))->capture($this->request(headers: $headers));
        $result = (new HeuristicScore())->evaluate($facts['flags'], ['requests' => 1]);
        self::assertSame(35, $result['score']);
        self::assertFalse($result['suspected']);
    }

    public function testCoherentBrowsersAndExpectedAbsenceOfChromiumHintsAreNeutral(): void
    {
        $signals = new HeuristicSignals(new HostingRanges());
        self::assertSame(0, $signals->capture($this->request(headers: $this->browserHeaders()))['flags']);
        foreach (['Mozilla/5.0 Version/18.0 Safari/605.1.15', 'Mozilla/5.0 Firefox/130.0'] as $ua) {
            $headers = $this->browserHeaders();
            $headers['User-Agent'] = $ua;
            unset($headers['Sec-CH-UA']);
            self::assertSame(0, $signals->capture($this->request(headers: $headers))['flags']);
        }
        $headers = $this->browserHeaders();
        $headers['User-Agent'] = 'Mozilla/5.0 Version/18.0 Safari/605.1.15';
        self::assertSame(HeuristicSignals::BROWSER_HEADERS_INCOHERENT, $signals->capture($this->request(headers: $headers))['flags']);
    }

    public function testCadenceNeedsSeveralIntervalsAndCannotBeInjectedAsARequestFlag(): void
    {
        $score = new HeuristicScore();
        self::assertSame(0, $score->evaluate(HeuristicScore::HIGH_CADENCE | HeuristicScore::REGULAR_CADENCE, ['requests' => 1])['score']);
        self::assertSame(0, $score->evaluate(0, ['requests' => 5, 'intervals' => 4, 'fast_intervals' => 4, 'regular_intervals' => 4])['score']);
        self::assertSame(25, $score->evaluate(0, ['requests' => 6, 'intervals' => 5, 'fast_intervals' => 5])['score']);
        self::assertSame(0, $score->evaluate(0, ['requests' => 20, 'intervals' => 19, 'fast_intervals' => 5])['score']);
        self::assertSame(25, $score->evaluate(0, ['requests' => 8, 'intervals' => 7, 'regular_intervals' => 7])['score']);
    }

    public function testCookieIgnoreRequiresActualOfferedCookieMissCounterNotMissingCookieAlone(): void
    {
        $score = new HeuristicScore();
        self::assertSame(0, $score->evaluate(0, ['requests' => 30, 'cookie_misses' => 0])['score']);
        self::assertSame(0, $score->evaluate(0, ['requests' => 3, 'cookie_misses' => 3])['score']);
        self::assertSame(25, $score->evaluate(0, ['requests' => 4, 'cookie_misses' => 3])['score']);
        $request = $this->request('/store-api/search');
        $request->headers->set('sw-context-token', 'private-context');
        $request->cookies->set('tracking', 'private-cookie');
        $signals = new HeuristicSignals(new HostingRanges());
        self::assertFalse($signals->capture($request)['cookiePresent']);
        self::assertTrue(($signals->capture($request)['flags'] & HeuristicSignals::API_WITHOUT_SESSION_COOKIE) !== 0);
        $request->cookies->set('session-example', 'private-session');
        self::assertTrue($signals->capture($request)['cookiePresent']);
        self::assertSame(0, $signals->capture($request)['flags'] & HeuristicSignals::API_WITHOUT_SESSION_COOKIE);
    }

    public function testHostingUsesOnlySymfonyTrustedClientAndNeverCdnOrPrivateAddresses(): void
    {
        $ranges = new HostingRanges();
        self::assertTrue($ranges->contains('35.180.1.2'));
        self::assertFalse($ranges->contains('35.180.1.2', true));
        self::assertFalse($ranges->contains('127.0.0.1'));
        self::assertFalse($ranges->contains('198.51.100.10'));
        self::assertFalse($ranges->contains(null));
        $proxies = Request::getTrustedProxies();
        $headers = Request::getTrustedHeaderSet();
        try {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
            $request = $this->request('/');
            $request->server->set('REMOTE_ADDR', '10.0.0.2');
            $request->headers->set('X-Forwarded-For', '35.180.1.2');
            $signals = new HeuristicSignals($ranges);
            self::assertSame(0, $signals->capture($request)['flags'] & HeuristicSignals::HOSTING_NETWORK);
            Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);
            self::assertSame(HeuristicSignals::HOSTING_NETWORK, $signals->capture($request)['flags'] & HeuristicSignals::HOSTING_NETWORK);
            self::assertSame(0, $signals->capture($request, true)['flags'] & HeuristicSignals::HOSTING_NETWORK);
        } finally {
            Request::setTrustedProxies($proxies, $headers);
        }
    }

    public function testScoresAndCountersAreBoundedAndCodesContainOnlyKnownNames(): void
    {
        $result = (new HeuristicScore())->evaluate(PHP_INT_MAX, ['requests' => PHP_INT_MAX, 'intervals' => PHP_INT_MAX, 'fast_intervals' => PHP_INT_MAX, 'regular_intervals' => PHP_INT_MAX, 'cookie_misses' => PHP_INT_MAX]);
        self::assertSame(100, $result['score']);
        self::assertSame(4095, $result['flags']);
        self::assertCount(12, HeuristicScore::codes($result['flags']));
        self::assertSame([], HeuristicScore::codes(8192));
        self::assertSame(0, (new HeuristicScore())->evaluate(0, ['requests' => -1, 'intervals' => -1, 'cookie_misses' => -1])['score']);
    }

    public function testInvalidOrMissingHostingSnapshotIsNeutral(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'agentenblick-hosting-');
        self::assertIsString($path);
        try {
            file_put_contents($path, '{invalid');
            self::assertFalse((new HostingRanges($path))->contains('35.180.1.2'));
            file_put_contents($path, json_encode(['schema' => 'agentenblick.hosting/1', 'ranges' => ['0.0.0.0/0']], JSON_THROW_ON_ERROR));
            self::assertFalse((new HostingRanges($path))->contains('35.180.1.2'));
        } finally {
            unlink($path);
        }
        self::assertFalse((new HostingRanges('/missing/agentenblick-hosting.json'))->contains('35.180.1.2'));
    }
}
