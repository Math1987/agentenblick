<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit\Benchmark;

use Aithos\Agentenblick\Benchmark\Manager;
use Aithos\Agentenblick\Controller\BenchmarkController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Acl\AclAnnotationValidator;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Exception\MissingPrivilegeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class BoundaryTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, bool}> */
    public static function privileges(): iterable
    {
        foreach (['status', 'preview', 'history', 'comparison'] as $method) {
            yield 'viewer ' . $method => [$method, ['agentenblick.viewer'], true];
            yield 'unauthorized ' . $method => [$method, [], false];
        }
        foreach (['enable', 'disable', 'send'] as $method) {
            yield 'viewer forbidden ' . $method => [$method, ['agentenblick.viewer'], false];
            yield 'editor ' . $method => [$method, ['agentenblick.editor'], true];
            yield 'unauthorized ' . $method => [$method, [], false];
        }
    }

    /** @param list<string> $privileges */
    #[DataProvider('privileges')]
    public function testNativeShopwareAclRequiresTheCorrectRight(string $method, array $privileges, bool $allowed): void
    {
        $class = new \ReflectionClass(BenchmarkController::class);
        $classRoute = $class->getAttributes(Route::class)[0]->newInstance();
        self::assertTrue($classRoute->getDefaults()['auth_required']);
        self::assertSame(['api'], $classRoute->getDefaults()[PlatformRequest::ATTRIBUTE_ROUTE_SCOPE]);
        $route = $class->getMethod($method)->getAttributes(Route::class)[0]->newInstance();
        $source = new AdminApiSource(str_repeat('b', 32));
        $source->setPermissions($privileges);
        $request = Request::create('/api/_action/agentenblick/benchmark/' . $method);
        $request->attributes->add($route->getDefaults());
        $request->attributes->set(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT, new Context($source));
        $event = new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn (): null => null, $request, HttpKernelInterface::MAIN_REQUEST);
        if (!$allowed) {
            $this->expectException(MissingPrivilegeException::class);
        }
        (new AclAnnotationValidator($this->createMock(Connection::class)))->validate($event);
        if ($allowed) {
            self::assertTrue(true);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConsents(): iterable
    {
        yield 'absent' => [[]];
        yield 'string true' => [['consent' => 'true', 'consentVersion' => '1', 'locale' => 'en-GB']];
        yield 'wrong version' => [['consent' => true, 'consentVersion' => '0', 'locale' => 'en-GB']];
        yield 'unknown language' => [['consent' => true, 'consentVersion' => '1', 'locale' => 'fr-FR']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidConsents')]
    public function testConsentCannotBeImplicitOrTakenFromAnUnknownNotice(array $body): void
    {
        $manager = (new \ReflectionClass(Manager::class))->newInstanceWithoutConstructor();
        $controller = new BenchmarkController($manager);
        $request = Request::create('/api/_action/agentenblick/benchmark/enable', 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
        $this->expectException(BadRequestHttpException::class);
        $controller->enable($request);
    }
}
