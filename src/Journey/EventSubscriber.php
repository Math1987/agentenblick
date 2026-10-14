<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Journey;

use Aithos\Agentenblick\Capture\FailureCounter;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\Event\CartChangedEvent;
use Shopware\Core\Checkout\Cart\Event\CartLoadedEvent;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Exception\InvalidCartException;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestResultEvent;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRouteResponse;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

final class EventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly RequestStack $requests, private readonly FailureCounter $failures)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductSearchResultEvent::class => ['onSearch', -1000], ProductSuggestResultEvent::class => ['onSuggest', -1000],
            ProductPageLoadedEvent::class => ['onProduct', -1000],
            AfterLineItemAddedEvent::class => ['onCart', -1000], CartChangedEvent::class => ['onCart', -1000], CartLoadedEvent::class => ['onCart', -1000],
            CheckoutConfirmPageLoadedEvent::class => ['onCheckout', -1000], CheckoutRegisterPageLoadedEvent::class => ['onCheckout', -1000],
            CheckoutFinishPageLoadedEvent::class => ['onFinish', -1000],
            CheckoutOrderPlacedEvent::class => ['onOrder', -1000], CheckoutOrderPlacedEvent::EVENT_NAME => ['onOrder', -1000],
            'kernel.exception' => ['onException', 1000],
        ];
    }

    public function onSearch(ProductSearchResultEvent $event): void
    {
        $this->observe($event->getRequest(), [Stage::Search], static fn (Signals $signals) => $signals->search($event->getResult()->getTotal()));
    }

    public function onSuggest(ProductSuggestResultEvent $event): void
    {
        $this->observe($event->getRequest(), [Stage::Search], static fn (Signals $signals) => $signals->search($event->getResult()->getTotal()));
    }

    public function onProduct(ProductPageLoadedEvent $event): void
    {
        $this->observe($event->getRequest(), [Stage::Product], static fn (Signals $signals) => $signals->product($event->getPage()->getProduct()));
    }

    public function recordProduct(Request $request, SalesChannelProductEntity $product): void
    {
        $this->observe($request, [Stage::Product], static fn (Signals $signals) => $signals->product($product));
    }

    public function recordProductResponse(Request $request, ProductDetailRouteResponse $response): void
    {
        $this->observe($request, [Stage::Product], static fn (Signals $signals) => $signals->product($response->getProduct()));
    }

    public function onCart(AfterLineItemAddedEvent|CartChangedEvent|CartLoadedEvent $event): void
    {
        $this->observe(null, [Stage::Cart, Stage::Checkout], static fn (Signals $signals) => $signals->cart($event->getCart()));
    }

    public function onCheckout(CheckoutConfirmPageLoadedEvent|CheckoutRegisterPageLoadedEvent $event): void
    {
        $this->observe($event->getRequest(), [Stage::Checkout], static function (Signals $signals) use ($event): void {
            $signals->stage(Stage::Checkout);
            $signals->known(Signals::KNOWN_CHECKOUT);
            $signals->cart($event->getPage()->getCart());
            if ($event->getRequest()->attributes->get('formViolations') instanceof ConstraintViolationException) {
                $signals->fail(Failure::CheckoutForm);
            }
        });
    }

    public function onFinish(CheckoutFinishPageLoadedEvent $event): void
    {
        $this->observe($event->getRequest(), [Stage::Checkout], static function (Signals $signals) use ($event): void {
            // A finish page can be reloaded. It never establishes an order on its own.
            if ($event->getRequest()->attributes->get('paymentFailed') === true) {
                $signals->known(Signals::KNOWN_PAYMENT);
                $signals->fail(Failure::PaymentError);
            }
        });
    }

    public function onOrder(CheckoutOrderPlacedEvent $event): void
    {
        $this->observe(null, null, static fn (Signals $signals) => $signals->order($event->getOrderId()));
    }

    public function onException(ExceptionEvent $event): void
    {
        $this->observe($event->getRequest(), [Stage::Cart, Stage::Checkout], static function (Signals $signals) use ($event): void {
            $error = $event->getThrowable();
            if ($error instanceof ConstraintViolationException) {
                $signals->known(Signals::KNOWN_CHECKOUT);
                $signals->fail(Failure::CheckoutForm);
            } elseif ($error instanceof InvalidCartException) {
                $signals->known(Signals::KNOWN_CART);
                $signals->fail(Failure::CartError);
            } elseif ($error instanceof PaymentException) {
                $signals->known(Signals::KNOWN_PAYMENT);
                $signals->fail(Failure::PaymentError);
            }
        });
    }

    /** @param ?list<Stage> $allowed
     * @param callable(Signals): void $capture
     */
    private function observe(?Request $source, ?array $allowed, callable $capture): void
    {
        try {
            $main = $this->requests->getMainRequest();
            $current = $this->requests->getCurrentRequest();
            if ($main === null || $current === null || $main->attributes->has('_sw_esi') || $current->attributes->has('_sw_esi')) {
                return;
            }
            $signals = Signals::read($main);
            if ($signals === null) {
                return;
            }
            $source ??= $current;
            if ($source->attributes->has('_sw_esi')) {
                return;
            }
            $stage = Stage::fromRequest($main);
            if ($source !== $main || $current !== $main) {
                $sameRequestClone = $current === $main && Signals::read($source) === $signals && $source->getPathInfo() === $main->getPathInfo();
                $checkoutForward = $current === $source && Stage::fromRequest($source) === Stage::Checkout
                    && ($stage === Stage::Checkout || $main->attributes->get('_route') === 'frontend.account.register.save');
                if (!$sameRequestClone && !$checkoutForward) {
                    return;
                }
                if ($checkoutForward) {
                    $stage = Stage::Checkout;
                }
            }
            if ($allowed !== null && !in_array($stage, $allowed, true)) {
                return;
            }
            $capture($signals);
        } catch (\Throwable $error) {
            $this->failures->record($error);
        }
    }
}
