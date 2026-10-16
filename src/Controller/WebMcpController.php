<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Controller;

use Aithos\Agentenblick\WebMcp\Declaration;
use Aithos\Agentenblick\WebMcp\Declarations;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront'], 'auth_required' => false, '_httpCache' => false])]
final class WebMcpController
{
    public function __construct(private readonly Declarations $declarations)
    {
    }

    #[Route(path: '/agentenblick/webmcp/declare', name: 'frontend.agentenblick.webmcp.declare', methods: ['POST'], defaults: ['XmlHttpRequest' => true])]
    public function declare(Request $request): JsonResponse
    {
        try {
            $declaration = Declaration::fromRequest($request);
            $this->declarations->declare($request, $declaration);
            return $this->json($declaration->response());
        } catch (\InvalidArgumentException $error) {
            return $this->json(['status' => 'error', 'reason' => $error->getMessage() === 'invalid_origin' ? 'invalid_origin' : 'invalid_declaration'], $error->getMessage() === 'invalid_origin' ? 403 : 400);
        } catch (\RuntimeException $error) {
            $reason = in_array($error->getMessage(), ['session_unavailable', 'session_busy', 'declaration_conflict'], true) ? $error->getMessage() : 'declaration_unavailable';
            return $this->json(['status' => 'error', 'reason' => $reason], $reason === 'declaration_unavailable' ? 503 : 409);
        } catch (\Throwable) {
            return $this->json(['status' => 'error', 'reason' => 'declaration_unavailable'], 503);
        }
    }

    /** @param array<string, string> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
