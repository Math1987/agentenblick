<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Capture\ObservationSubscriber;
use Aithos\Agentenblick\Controller\HeuristicsController;
use Aithos\Agentenblick\Heuristics\History;
use Aithos\Agentenblick\Heuristics\HeuristicScore;
use Aithos\Agentenblick\Heuristics\Settings;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class HeuristicHistorySettingsTest extends TestCase
{
    public function testAnUnacknowledgedCookieOfferPersistsWithoutBeingReissued(): void
    {
        $state = History::advance([], 0, false, true, 1000);
        foreach ([2000, 3000, 4000] as $at) {
            $state = History::advance($state, 0, false, false, $at);
        }
        self::assertSame(3, $state['cookie_misses']);
        self::assertTrue($state['cookie_offered']);
        $state = History::advance($state, 0, true, false, 5000);
        self::assertFalse($state['cookie_offered']);
        $state = History::advance($state, 0, false, false, 6000);
        self::assertSame(3, $state['cookie_misses']);
    }

    public function testEqualTimestampIsFastAndLateObservationsDoNotRegressFacts(): void
    {
        $state = History::advance([], 8, false, false, 2000);
        $state = History::advance($state, 4, false, false, 2000);
        self::assertSame(1, $state['fast_intervals']);
        $state = History::advance($state, 0, false, true, 1000);
        self::assertSame(2000, $state['last_ms']);
        self::assertSame(1, $state['intervals']);
        self::assertFalse($state['cookie_offered']);
        self::assertSame(12, $state['flags']);
        for ($i = 0; $i < 300; ++$i) {
            $state = History::advance($state, 65535, false, false, 3000 + $i);
        }
        self::assertSame(255, $state['requests']);
        self::assertLessThanOrEqual(254, $state['intervals']);
        self::assertSame(4095, $state['flags']);
    }

    public function testLongMixedTrafficDoesNotBecomeFastBecauseCountersSaturate(): void
    {
        $state = [];
        $at = 1000;
        for ($i = 0; $i < 256; ++$i) {
            $state = History::advance($state, 0, true, false, $at += 1000);
        }
        for ($i = 0; $i < 179; ++$i) {
            $state = History::advance($state, 0, true, false, $at += 100);
            $state = History::advance($state, 0, true, false, $at += 1000);
        }
        $result = (new HeuristicScore())->evaluate($state['flags'], $state);
        self::assertSame(0, $result['flags'] & HeuristicScore::HIGH_CADENCE);
        self::assertLessThan(0.55, $state['fast_intervals'] / $state['intervals']);
    }

    public function testDefaultOffFreshEffectiveConfigAndEpochChanges(): void
    {
        $values = [];
        $config = $this->createMock(SystemConfigService::class);
        $config->method('getDomain')->willReturnCallback(static function () use (&$values): array {
            return $values;
        });
        $config->method('setMultiple')->willReturnCallback(static function (array $updated) use (&$values): void {
            $values = array_replace($values, $updated);
        });
        $settings = new Settings($config, $this->transactionConnection());
        self::assertFalse($settings->read()['enabled']);
        self::assertSame(60, $settings->read()['threshold']);
        self::assertTrue($settings->read()['experimental']);
        self::assertFalse($settings->read()['calibrated']);
        $before = $settings->context()['epoch'];
        $settings->save(true, 60);
        $first = $settings->context()['epoch'];
        self::assertNotSame($before, $first);
        $settings->save(true, 60);
        self::assertSame($first, $settings->context()['epoch']);
        $settings->save(false, 60);
        self::assertNotSame($first, $settings->context()['epoch']);
        $settings->save(true, 80);
        self::assertSame(80, $settings->read()['threshold']);
        // Managed effective values returned by getDomain take effect immediately.
        $values[Settings::ENABLED] = false;
        self::assertFalse($settings->context()['enabled']);
        self::assertArrayNotHasKey('epoch', $settings->read());
    }

    public function testSettingsRouteRequiresViewerForReadAndEditorForWrite(): void
    {
        foreach (['read' => ['GET', 'agentenblick.viewer'], 'write' => ['POST', 'agentenblick.editor']] as $method => [$verb, $permission]) {
            $route = (new \ReflectionMethod(HeuristicsController::class, $method))->getAttributes(Route::class)[0]->newInstance();
            self::assertSame([$verb], $route->getMethods());
            self::assertSame([$permission], $route->getDefaults()[PlatformRequest::ATTRIBUTE_ACL]);
        }
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(self::never())->method('setMultiple');
        $controller = new HeuristicsController(new Settings($config, $this->transactionConnection()));
        $this->expectException(BadRequestHttpException::class);
        $controller->write(Request::create('/', 'POST', [], [], [], [], '{"enabled":true,"threshold":29}'));
    }

    public function testEsiFragmentCannotReplaceMainObservationAndDeletedCookieIsNotAnOffer(): void
    {
        $observer = (new \ReflectionClass(ObservationSubscriber::class))->newInstanceWithoutConstructor();
        $main = Request::create('https://shop.test/');
        $observer->begin($main);
        $main->attributes->set('_route', 'frontend.home.page');
        $observer->complete($main, 200, false);
        $transformed = Request::create('https://shop.test/navigation/internal-id');
        $transformed->attributes->set('_route', 'frontend.navigation.page');
        $observer->complete($transformed, 200, false);
        $fragment = Request::create('https://shop.test/widgets/footer');
        $fragment->attributes->set('_sw_esi', true);
        $fragment->attributes->set('_route', 'frontend.footer');
        $observer->complete($fragment, 500, true);
        $captured = (new \ReflectionProperty($observer, 'request'))->getValue($observer);
        self::assertInstanceOf(Request::class, $captured);
        self::assertSame('frontend.navigation.page', $captured->attributes->get('_route'));
        self::assertSame(200, (new \ReflectionProperty($observer, 'status'))->getValue($observer));
        self::assertFalse((new \ReflectionProperty($observer, 'sessionCookieOffered'))->getValue($observer));
        $response = new Response();
        $response->headers->setCookie(Cookie::create('session-shop', 'opaque'));
        self::assertTrue(ObservationSubscriber::offersSessionCookie($response));
        $response->headers->clearCookie('session-shop');
        self::assertFalse(ObservationSubscriber::offersSessionCookie($response));
    }
    private function transactionConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        return $connection;
    }

}
