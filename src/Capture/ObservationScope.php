<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Capture;

use Symfony\Component\HttpFoundation\Request;

final class ObservationScope
{
    public function accepts(Request $request): bool
    {
        $path = $request->getPathInfo();
        if (str_ends_with($path, '/agentenblick/webmcp/declare')) {
            return false;
        }
        return !preg_match('~^/(?:admin|api|_profiler|_wdt|bundles|theme|media|thumbnail|assets)(?:/|$)~i', $path)
            && !preg_match('~\.(?:css|js|map|png|jpe?g|gif|webp|avif|ico|svg|woff2?|ttf|eot|mp4|pdf)$~i', $path);
    }

    public function route(Request $request): string
    {
        $route = $request->attributes->get('_route');
        if (is_string($route) && $route !== '') {
            return substr($route, 0, 190);
        }
        $path = $request->getPathInfo();
        if (preg_match('~^/(robots\.txt|llms(?:-full)?\.txt|agents\.md)$~i', $path)) {
            return 'discovery:' . strtolower(ltrim($path, '/'));
        }
        foreach (['/.well-known/', '/ucp/', '/store-api/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return rtrim($prefix, '/') . '/{unresolved}';
            }
        }
        return $path === '/' ? 'frontend.home.page' : '/{unresolved}';
    }
}
