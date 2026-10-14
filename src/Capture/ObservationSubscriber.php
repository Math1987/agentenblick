<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Capture;

use Aithos\Agentenblick\Journey\Signals;
use Aithos\Agentenblick\Persistence\DailyKey;
use Aithos\Agentenblick\Persistence\ObservationStore;
use Aithos\Agentenblick\Persistence\ProxyDiagnostics;
use Aithos\Agentenblick\Verification\CatalogueProvider;
use Aithos\Agentenblick\Verification\Classifier;
use Aithos\Agentenblick\Verification\SafeSignatureContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\Service\ResetInterface;

final class ObservationSubscriber implements EventSubscriberInterface, ResetInterface
{
    private ?Request $request = null;
    private ?\DateTimeImmutable $at = null;
    private int $status = 0;

    public function __construct(
        private readonly DailyKey $dailyKey,
        private readonly Classifier $classifier,
        private readonly ObservationStore $store,
        private readonly FailureCounter $failures,
        private readonly CatalogueProvider $catalogues,
        private readonly ProxyDiagnostics $proxyDiagnostics,
        private readonly SalesChannelResolver $salesChannels,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['onRequest', 10000], 'kernel.response' => ['onResponse', -10000], 'kernel.terminate' => ['onTerminate', -10000]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        // The outer kernel already captured the original incoming headers. Shopware
        // may subsequently inject a context token; it must not become visitor evidence.
        if ($this->request === null && !$event->getRequest()->attributes->has('_sw_esi')) {
            $this->begin($event->getRequest());
        }
    }

    public function begin(Request $request): void
    {
        $this->reset();
        try {
            if ((new ObservationScope())->accepts($request)) {
                Signals::initialize($request);
                $this->request = clone $request;
                $this->at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }
        } catch (\Throwable $error) {
            $this->reset();
            $this->failures->record($error);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->request !== null) {
            $this->complete($event->getRequest(), $event->getResponse()->getStatusCode());
        }
    }

    public function complete(Request $request, int $status): void
    {
        $this->status = $status;
        if ($this->request === null) {
            return;
        }
        foreach (['_route', 'sw-sales-channel-id'] as $attribute) {
            if ($request->attributes->has($attribute)) {
                $this->request->attributes->set($attribute, $request->attributes->get($attribute));
            }
        }
        $signals = Signals::read($request);
        if ($signals !== null) {
            $target = Signals::read($this->request) ?? Signals::initialize($this->request);
            $target->merge($signals);
        }
    }

    public function responseStatus(int $status): void
    {
        $this->status = $status;
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $this->flush();
    }

    public function flush(): void
    {
        $request = $this->request;
        $at = $this->at;
        $status = $this->status;
        $this->reset();
        if ($request === null || $at === null || $status === 0) {
            return;
        }
        try {
            $catalogue = $this->catalogues->load();
            $this->salesChannels->resolve($request);
            $key = $this->dailyKey->current($at->format('Y-m-d'));
            $classification = $this->classifier->classify($request, $catalogue, hash_hmac('sha256', "ip\0" . ($request->getClientIp() ?? ''), $key), $at->getTimestamp());
            $signatureContext = (new SafeSignatureContext())->capture($request, $catalogue, $classification);
            $row = (new Minimizer())->minimize($request, $classification, $key, $at, $status, $signatureContext);
            sodium_memzero($key);
            $this->store->record($row);
            $this->proxyDiagnostics->record($catalogue->isCdn($request->getClientIp()));
        } catch (\Throwable $error) {
            $this->failures->record($error);
        }
    }

    public function reset(): void
    {
        $this->request = null;
        $this->at = null;
        $this->status = 0;
    }
}
