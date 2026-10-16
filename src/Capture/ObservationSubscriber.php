<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Capture;

use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Heuristics\Candidate;
use Aithos\Agentenblick\Heuristics\HeuristicSignals;
use Aithos\Agentenblick\Heuristics\Settings;
use Aithos\Agentenblick\Health\Observation;
use Aithos\Agentenblick\Health\ProbeTokens;
use Aithos\Agentenblick\Journey\Signals;
use Aithos\Agentenblick\Persistence\DailyKey;
use Aithos\Agentenblick\Persistence\ObservationStore;
use Aithos\Agentenblick\Persistence\ProxyDiagnostics;
use Aithos\Agentenblick\Verification\CatalogueProvider;
use Aithos\Agentenblick\Verification\Classifier;
use Aithos\Agentenblick\Verification\SafeSignatureContext;
use Aithos\Agentenblick\WebMcp\Declarations;
use Aithos\Agentenblick\WebMcp\Scope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\Service\ResetInterface;

final class ObservationSubscriber implements EventSubscriberInterface, ResetInterface
{
    private ?Request $request = null;
    private ?\DateTimeImmutable $at = null;
    private int $status = 0;
    private bool $sessionCookieOffered = false;
    private bool $selfProbe = false;

    public function __construct(
        private readonly DailyKey $dailyKey,
        private readonly Classifier $classifier,
        private readonly ObservationStore $store,
        private readonly FailureCounter $failures,
        private readonly CatalogueProvider $catalogues,
        private readonly ProxyDiagnostics $proxyDiagnostics,
        private readonly SalesChannelResolver $salesChannels,
        private readonly Settings $heuristics,
        private readonly HeuristicSignals $heuristicSignals,
        private readonly ProbeTokens $probeTokens,
        private readonly Observation $health,
        private readonly ?Scope $webMcp = null,
        private readonly ?Declarations $declarations = null,
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
        if ($this->request === null && !$this->selfProbe && !$event->getRequest()->attributes->has('_sw_esi')) {
            $this->begin($event->getRequest());
        }
    }

    public function begin(Request $request): void
    {
        $this->reset();
        try {
            if ($request->headers->has(ProbeTokens::HEADER) && $this->probeTokens->consume($request)) {
                $this->selfProbe = true;
                return;
            }
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
            $this->complete($event->getRequest(), $event->getResponse()->getStatusCode(), self::offersSessionCookie($event->getResponse()));
        }
    }

    public function complete(Request $request, int $status, bool $sessionCookieOffered = false): void
    {
        // HttpCache may dispatch an ESI fragment as MAIN_REQUEST. It cannot
        // replace the route, outcome or cookie facts of the incoming request.
        // A legitimate Shopware SEO transformation may change the main path.
        if ($this->request === null || $request->attributes->has('_sw_esi') || $request->getMethod() !== $this->request->getMethod()) {
            return;
        }
        $this->status = $status;
        $this->sessionCookieOffered = $sessionCookieOffered;
        foreach (['_route', 'sw-sales-channel-id', 'sw-domain-id', Scope::ATTRIBUTE] as $attribute) {
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
        $sessionCookieOffered = $this->sessionCookieOffered;
        $this->reset();
        if ($request === null || $at === null || $status === 0) {
            return;
        }
        // terminate runs after the response was sent; only successful llms detail
        // retains this lower bound for subsequent activity.
        $request->attributes->set('_agentenblick_completed_at', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'));
        try {
            $catalogue = $this->catalogues->load();
            $this->salesChannels->resolve($request);
            if ($this->webMcp !== null && !$request->attributes->has(Scope::ATTRIBUTE)) {
                $this->webMcp->prepare($request);
            }
            $key = $this->dailyKey->current($at->format('Y-m-d'));
            try {
                $classification = $this->classifier->classify($request, $catalogue, hash_hmac('sha256', "ip\0" . ($request->getClientIp() ?? ''), $key), $at->getTimestamp());
                $classification = $this->declarations?->classify($request, $classification, $key, $at) ?? $classification;
                if ($classification->isNonHuman()) {
                    $this->health->prepare($request);
                }
                $signatureContext = (new SafeSignatureContext())->capture($request, $catalogue, $classification);
                $row = (new Minimizer())->minimize($request, $classification, $key, $at, $status, $signatureContext);
                $candidate = null;
                if ($classification->kind === Kind::HumanOrUnknown && $classification->agent === 'unknown' && $this->heuristics->context()['enabled']) {
                    $facts = $this->heuristicSignals->capture($request, $catalogue->isCdn($request->getClientIp()));
                    $candidate = new Candidate(
                        $facts['flags'],
                        $facts['cookiePresent'],
                        $sessionCookieOffered,
                        fn (): array => $this->heuristics->context(),
                        function () use ($request, $key, $at, $status): array {
                            $this->health->prepare($request);
                            return (new Minimizer())->minimize($request, new Classification('unknown', 'unknown', Kind::AutomationUnknown, Evidence::Suspected, 'heuristic_threshold'), $key, $at, $status);
                        },
                    );
                }
                $committed = $this->store->record($row, $candidate);
                unset($candidate);
                $this->health->record($request, $committed);
            } finally {
                sodium_memzero($key);
            }
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
        $this->sessionCookieOffered = false;
        $this->selfProbe = false;
    }

    public static function offersSessionCookie(Response $response): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'session-') && !$cookie->isCleared()) {
                return true;
            }
        }
        return false;
    }
}
