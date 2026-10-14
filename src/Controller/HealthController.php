<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Controller;

use Aithos\Agentenblick\Admin\ReportFilter;
use Aithos\Agentenblick\Health\HealthReader;
use Aithos\Agentenblick\Health\ProbeService;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID], 'auth_required' => true])]
final class HealthController
{
    public function __construct(private readonly HealthReader $reader, private readonly ProbeService $probes)
    {
    }

    #[Route(path: '/api/_action/agentenblick/health', name: 'api.action.agentenblick.health.read', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.viewer']])]
    public function read(Request $request): JsonResponse
    {
        $domain = $request->query->all()['domainId'] ?? null;
        if ($domain !== null && (!is_string($domain) || !preg_match('/^[a-f0-9]{32}$/D', $domain))) {
            throw new BadRequestHttpException('Invalid domain selection.');
        }
        return $this->json($this->reader->read(ReportFilter::fromRequest($request), $domain));
    }

    #[Route(path: '/api/_action/agentenblick/health/probe', name: 'api.action.agentenblick.health.probe', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['agentenblick.editor']])]
    public function probe(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 2048) {
            throw new BadRequestHttpException('Probe payload too large.');
        }
        $body = $request->toArray();
        if (count($body) !== 2 || !is_string($body['domainId'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $body['domainId']) || !is_bool($body['autoTest'] ?? null)) {
            throw new BadRequestHttpException('Expected a configured domainId and autoTest boolean.');
        }
        try {
            return $this->json($this->probes->run($body['domainId'], $body['autoTest']));
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            $reason = in_array($error->getMessage(), ['offline', 'probe_in_progress'], true) ? $error->getMessage() : 'probe_unavailable';
            return $this->json(['completed' => false, 'reasonCode' => $reason], $reason === 'probe_in_progress' ? 409 : 503);
        }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
