<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Controller;

use Aithos\Agentenblick\Admin\AnalyticsReader;
use Aithos\Agentenblick\Admin\CsvExporter;
use Aithos\Agentenblick\Admin\RangeApprovals;
use Aithos\Agentenblick\Admin\ReportFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID], 'auth_required' => true])]
final class AnalyticsController
{
    public function __construct(private readonly AnalyticsReader $reader, private readonly RangeApprovals $ranges)
    {
    }

    #[Route(path: '/api/_action/agentenblick/summary', name: 'api.action.agentenblick.summary', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function summary(Request $request, Context $context): JsonResponse
    {
        return $this->json($this->reader->summary(ReportFilter::fromRequest($request), $context->getLanguageId()));
    }

    #[Route(path: '/api/_action/agentenblick/agents', name: 'api.action.agentenblick.agents', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function agents(Request $request): JsonResponse
    {
        return $this->json($this->reader->agents(ReportFilter::fromRequest($request)));
    }

    #[Route(path: '/api/_action/agentenblick/export', name: 'api.action.agentenblick.export', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function export(Request $request): Response
    {
        $filter = ReportFilter::fromRequest($request);
        $data = $this->reader->agents($filter, true);
        return new Response(CsvExporter::contents($data['items']), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="agentenblick-' . $filter->period()['since'] . '.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    #[Route(path: '/api/_action/agentenblick/ranges/pending', name: 'api.action.agentenblick.ranges.pending', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function pending(): JsonResponse
    {
        return $this->json(['items' => $this->ranges->pending()]);
    }

    #[Route(path: '/api/_action/agentenblick/ranges/{agent}/approve', name: 'api.action.agentenblick.ranges.approve', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.editor']])]
    public function approve(string $agent, Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 2048) {
            throw new BadRequestHttpException('Approval payload too large.');
        }
        $body = $request->toArray();
        if (!is_string($body['version'] ?? null)) {
            throw new BadRequestHttpException('A reviewed version is required.');
        }
        $this->ranges->approve($agent, $body['version']);
        return $this->json(['success' => true, 'agent' => $agent]);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
    }
}
