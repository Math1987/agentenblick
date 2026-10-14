<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit\Health;

use Aithos\Agentenblick\Controller\HealthController;
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
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class HealthBoundaryTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, bool}> */
    public static function permissions(): iterable
    {
        yield 'viewer reads stored results' => ['read', ['agentenblick.viewer'], true];
        yield 'viewer cannot start network' => ['probe', ['agentenblick.viewer'], false];
        yield 'editor may explicitly probe' => ['probe', ['agentenblick.editor'], true];
        yield 'anonymous cannot read' => ['read', [], false];
        yield 'anonymous cannot probe' => ['probe', [], false];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function testNativeAclDistinguishesReadingFromNetworkActions(string $method, array $permissions, bool $allowed): void
    {
        $class = new \ReflectionClass(HealthController::class);
        $defaults = $class->getAttributes(Route::class)[0]->newInstance()->getDefaults();
        self::assertTrue($defaults['auth_required']);
        self::assertSame(['api'], $defaults[PlatformRequest::ATTRIBUTE_ROUTE_SCOPE]);
        $route = $class->getMethod($method)->getAttributes(Route::class)[0]->newInstance();
        self::assertSame([$method === 'read' ? 'GET' : 'POST'], $route->getMethods());
        $source = new AdminApiSource(str_repeat('a', 32));
        $source->setPermissions($permissions);
        $request = Request::create('/api/_action/agentenblick/health');
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
}
