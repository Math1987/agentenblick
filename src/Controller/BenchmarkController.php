<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Controller;

use Aithos\Agentenblick\Benchmark\Manager;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID], 'auth_required' => true])]
final class BenchmarkController
{
    public function __construct(private readonly Manager $manager)
    {
    }

    #[Route(path: '/api/_action/agentenblick/benchmark/status', name: 'api.action.agentenblick.benchmark.status', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function status(): JsonResponse
    {
        return $this->json($this->manager->status());
    }

    #[Route(path: '/api/_action/agentenblick/benchmark/preview', name: 'api.action.agentenblick.benchmark.preview', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function preview(Request $request): JsonResponse
    {
        $period = $request->query->all()['period'] ?? null;
        if ($period !== null && !is_string($period)) {
            throw new BadRequestHttpException('Invalid period.');
        }
        try {
            return $this->json($this->manager->preview($period));
        } catch (\InvalidArgumentException $error) {
            throw new BadRequestHttpException($error->getMessage());
        }
    }

    #[Route(path: '/api/_action/agentenblick/benchmark/history', name: 'api.action.agentenblick.benchmark.history', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function history(): JsonResponse
    {
        return $this->json($this->manager->history());
    }

    #[Route(path: '/api/_action/agentenblick/benchmark/comparison', name: 'api.action.agentenblick.benchmark.comparison', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function comparison(): JsonResponse
    {
        return $this->json($this->manager->comparison());
    }

    #[Route(path: '/api/_action/agentenblick/benchmark/enable', name: 'api.action.agentenblick.benchmark.enable', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.editor']])]
    public function enable(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 2048) {
            throw new BadRequestHttpException('Consent payload too large.');
        }
        $body = $request->toArray();
        if (($body['consent'] ?? false) !== true || ($body['consentVersion'] ?? null) !== '1' || !in_array($body['locale'] ?? null, ['de-DE', 'en-GB'], true)) {
            throw new BadRequestHttpException('Explicit consent to version 1 in German or English is required.');
        }
        return $this->json($this->manager->enable($body['locale']));
    }

    #[Route(path: '/api/_action/agentenblick/benchmark/disable', name: 'api.action.agentenblick.benchmark.disable', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.editor']])]
    public function disable(): JsonResponse
    {
        return $this->json($this->manager->disable());
    }

    #[Route(path: '/api/_action/agentenblick/benchmark/send', name: 'api.action.agentenblick.benchmark.send', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.editor']])]
    public function send(): JsonResponse
    {
        return $this->json($this->manager->send());
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
