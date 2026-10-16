<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Capture;

use Aithos\Agentenblick\WebMcp\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/** Decorates the outside of Shopware's cache; never dispatches other plugins' events on a hit. */
final class ObservedKernel implements HttpKernelInterface, TerminableInterface
{
    private int $depth = 0;
    public function __construct(private readonly HttpKernelInterface $inner, private readonly ObservationSubscriber $observer, private readonly ?Scope $webMcp = null)
    {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $main = $type === self::MAIN_REQUEST && $this->depth === 0;
        if ($main) {
            $this->observer->begin($request);
        }
        ++$this->depth;
        try {
            $response = $this->inner->handle($request, $type, $catch);
        } finally {
            --$this->depth;
        }
        if ($main) {
            if ($this->webMcp !== null && (new ObservationScope())->accepts($request) && str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
                try {
                    $this->webMcp->prepare($request, $response);
                } catch (\Throwable) {
                    // Optional declaration support must never interrupt the storefront.
                }
            }
            $this->observer->complete($request, $response->getStatusCode(), ObservationSubscriber::offersSessionCookie($response));
        }
        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($this->inner instanceof TerminableInterface) {
                $this->inner->terminate($request, $response);
            }
        } finally {
            // Symfony deliberately skips kernel.terminate on a fresh HTTP-cache hit.
            // The runtime calls this outer method AFTER sending/flushing the response.
            // A regular kernel.terminate has already cleared the state: no double counting.
            $this->observer->flush();
        }
    }
}
