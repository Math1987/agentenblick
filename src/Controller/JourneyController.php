<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Controller;

use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Journey\JourneyReader;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID], 'auth_required' => true])]
final class JourneyController
{
    public function __construct(private readonly JourneyReader $reader)
    {
    }

    #[Route(path: '/api/_action/agentenblick/journey/summary', name: 'api.action.agentenblick.journey.summary', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function summary(Request $request): JsonResponse
    {
        return $this->json($this->reader->summary(ReportFilter::fromRequest($request)));
    }

    #[Route(path: '/api/_action/agentenblick/journey/sessions', name: 'api.action.agentenblick.journey.sessions', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function sessions(Request $request): JsonResponse
    {
        return $this->json($this->reader->sessions(ReportFilter::fromRequest($request)));
    }

    #[Route(path: '/api/_action/agentenblick/journey/sessions/{id}', name: 'api.action.agentenblick.journey.detail', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function detail(string $id, Request $request): JsonResponse
    {
        return $this->json($this->reader->detail($id, ReportFilter::fromRequest($request)));
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
