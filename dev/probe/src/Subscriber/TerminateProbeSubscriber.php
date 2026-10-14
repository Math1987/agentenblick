<?php

declare(strict_types=1);

namespace Agentenblick\TerminateProbe\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/** Only mounted in the isolated local bench, never included in the distributable. */
final class TerminateProbeSubscriber implements EventSubscriberInterface
{
    private ?array $probe = null;

    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['onRequest', 10000], 'kernel.terminate' => ['onTerminate', 10000]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->getPathInfo() !== '/_agentenblick/terminate-probe') {
            return;
        }
        $id = bin2hex(random_bytes(8));
        $delay = $event->getRequest()->query->getInt('delay_ms', 0) === 2000 ? 2000 : 0;
        $this->probe = ['id' => $id, 'delay_ms' => $delay];
        $event->setResponse(new JsonResponse([
            'id' => $id, 'delay_ms' => $delay,
            'sapi' => PHP_SAPI, 'php' => PHP_VERSION,
            'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
            'before_response' => microtime(true),
        ], 200, ['Cache-Control' => 'no-store']));
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $probe = $this->probe;
        $this->probe = null;
        if (!is_array($probe)) {
            return;
        }
        $started = microtime(true);
        usleep($probe['delay_ms'] * 1000);
        file_put_contents('/tmp/agentenblick-terminate-probe.jsonl', json_encode([
            'id' => $probe['id'], 'delay_ms' => $probe['delay_ms'],
            'terminate_started' => $started, 'terminate_finished' => microtime(true),
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }
}
