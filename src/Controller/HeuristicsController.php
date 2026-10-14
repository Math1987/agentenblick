<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Controller;

use Aithos\Agentenblick\Heuristics\Settings;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID], 'auth_required' => true])]
final class HeuristicsController
{
    public function __construct(private readonly Settings $settings)
    {
    }

    #[Route(path: '/api/_action/agentenblick/heuristics/settings', name: 'api.action.agentenblick.heuristics.read', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function read(): JsonResponse
    {
        return $this->json($this->settings->read());
    }

    #[Route(path: '/api/_action/agentenblick/heuristics/settings', name: 'api.action.agentenblick.heuristics.write', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.editor']])]
    public function write(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 2048) {
            throw new BadRequestHttpException('Settings payload too large.');
        }
        $body = $request->toArray();
        if (count($body) !== 2 || !is_bool($body['enabled'] ?? null) || !is_int($body['threshold'] ?? null) || $body['threshold'] < 30 || $body['threshold'] > 100) {
            throw new BadRequestHttpException('Expected enabled boolean and threshold integer between 30 and 100.');
        }
        return $this->json($this->settings->save($body['enabled'], $body['threshold']));
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
