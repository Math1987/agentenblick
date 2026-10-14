<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Admin\AclPrivilegesSubscriber;
use Aithos\Agentenblick\Admin\CsvExporter;
use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Controller\AnalyticsController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Acl\AclAnnotationValidator;
use Shopware\Core\Framework\Api\Acl\Event\AclGetAdditionalPrivilegesEvent;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Exception\MissingPrivilegeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AdminBoundaryTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, bool}> */
    public static function permissions(): iterable
    {
        yield 'viewer summary' => ['summary', ['agentenblick.viewer'], true];
        yield 'viewer agents' => ['agents', ['agentenblick.viewer'], true];
        yield 'viewer CSV' => ['export', ['agentenblick.viewer'], true];
        yield 'viewer pending' => ['pending', ['agentenblick.viewer'], true];
        yield 'viewer cannot approve' => ['approve', ['agentenblick.viewer'], false];
        yield 'editor may approve' => ['approve', ['agentenblick.editor'], true];
        foreach (['summary', 'agents', 'export', 'pending', 'approve'] as $method) {
            yield 'no rights ' . $method => [$method, [], false];
        }
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function testNativeShopwareValidatorEnforcesTheActualRoutePrivileges(string $method, array $permissions, bool $allowed): void
    {
        $class = new \ReflectionClass(AnalyticsController::class);
        $classRoute = $class->getAttributes(Route::class)[0]->newInstance();
        self::assertTrue($classRoute->getDefaults()['auth_required']);
        self::assertSame(['api'], $classRoute->getDefaults()[PlatformRequest::ATTRIBUTE_ROUTE_SCOPE]);
        $route = $class->getMethod($method)->getAttributes(Route::class)[0]->newInstance();
        $source = new AdminApiSource(str_repeat('a', 32));
        $source->setPermissions($permissions);
        $request = Request::create('/api/_action/agentenblick/' . $method);
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

    public function testPrivilegesAreDiscoverableAndNotDuplicated(): void
    {
        $event = new AclGetAdditionalPrivilegesEvent(Context::createDefaultContext(), ['other.viewer', 'agentenblick.viewer']);
        (new AclPrivilegesSubscriber())->addPrivileges($event);
        self::assertSame(['other.viewer', 'agentenblick.viewer', 'agentenblick.editor'], $event->getPrivileges());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidFilters(): iterable
    {
        yield 'too long period' => [['days' => 400]];
        yield 'fractional period' => [['days' => '7.5']];
        yield 'SQL as channel' => [['salesChannelId' => "' OR 1=1 --"]];
        yield 'zero page' => [['page' => 0]];
        yield 'unbounded limit' => [['limit' => 5000]];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidFilters')]
    public function testPublicFiltersAreBounded(array $query): void
    {
        $this->expectException(BadRequestHttpException::class);
        ReportFilter::fromRequest(new Request($query));
    }

    public function testCsvKeepsUtf8AndEscapesSpreadsheetFormulaCells(): void
    {
        $items = [];
        foreach (['=HYPERLINK("https://invalid.test")', ' +123', '-42', '@SUM(A1)', "\t=1+1", 'Équipe de test'] as $agent) {
            $items[] = ['operator' => 'operator', 'agent' => $agent, 'kind' => 'browsing_agent', 'evidence' => 'verified_signature', 'requests' => 2, 'sessions' => 1, 'lastSeen' => null, 'byEvidence' => [], 'topRoutes' => []];
        }
        $csv = CsvExporter::contents($items);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'w+');
        self::assertNotFalse($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        fgetcsv($stream, escape: '');
        foreach ($items as $index => $item) {
            $row = fgetcsv($stream, escape: '');
            self::assertIsArray($row);
            self::assertSame($index < 5 ? "'" . $item['agent'] : $item['agent'], $row[1]);
            self::assertSame('2', $row[4]);
        }
        fclose($stream);
    }
}
