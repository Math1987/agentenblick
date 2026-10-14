<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Capture\FailureCounter;
use Aithos\Agentenblick\Capture\Minimizer;
use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Journey\EventSubscriber;
use Aithos\Agentenblick\Journey\Failure;
use Aithos\Agentenblick\Journey\ProductDetailRouteDecorator;
use Aithos\Agentenblick\Journey\Signals;
use Aithos\Agentenblick\Journey\Stage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Content\Product\Cart\ProductOutOfStockError;
use Shopware\Core\Content\Product\Cart\ProductStockReachedError;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestResultEvent;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRouteResponse;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolationList;

final class JourneyCaptureTest extends TestCase
{
    private RequestStack $stack;
    private EventSubscriber $subscriber;
    private string $directory;

    protected function setUp(): void
    {
        $this->stack = new RequestStack();
        $this->directory = sys_get_temp_dir() . '/agentenblick-journey-test-' . bin2hex(random_bytes(8));
        $this->subscriber = new EventSubscriber($this->stack, new FailureCounter($this->directory, new NullLogger()));
    }

    protected function tearDown(): void
    {
        if (is_file($this->directory . '/var/agentenblick/errors.json')) {
            unlink($this->directory . '/var/agentenblick/errors.json');
            rmdir($this->directory . '/var/agentenblick');
            rmdir($this->directory . '/var');
            rmdir($this->directory);
        }
    }

    public function testBoundedStagesDistinguishOrderAttemptFromOrderAndUnknownSeoCacheHits(): void
    {
        foreach (['store-api.checkout.cart.add' => Stage::Cart, 'store-api.search.suggest' => Stage::Search, 'store-api.checkout.cart.order' => Stage::Checkout, 'frontend.checkout.finish.page' => Stage::Checkout] as $route => $stage) {
            $request = Request::create('/private-path?search=secret');
            $request->attributes->set('_route', $route);
            self::assertSame($stage, Stage::fromRequest($request));
        }
        self::assertNull(Stage::fromRequest(Request::create('/personal-product-slug?email=private@example.test')));
        self::assertSame(Stage::Discover, Stage::fromRequest(Request::create('/.well-known/ucp')));
        self::assertSame(Stage::Product, Stage::fromRequest(Request::create('/store-api/product/' . str_repeat('a', 32))));
        self::assertSame(Stage::Checkout, Stage::fromRequest(Request::create('/ucp/v1/checkout-sessions/private-id')));
        $cached = Signals::minimized(Request::create('/search?search=secret'), 200, true);
        self::assertSame(Signals::KNOWN_HTTP, $cached['known_mask']);
        self::assertSame(0, $cached['failure_mask']);
    }

    public function testSearchAndSuggestOnlyRetainZeroFlagNotTermsResultsOrBodies(): void
    {
        $request = $this->main('store-api.search', '/store-api/search?search=private-term');
        foreach ([ProductSearchResultEvent::class, ProductSuggestResultEvent::class] as $class) {
            $result = $this->createMock(ProductListingResult::class);
            $result->method('getTotal')->willReturn(0);
            $event = $this->createMock($class);
            $event->method('getRequest')->willReturn($request);
            $event->method('getResult')->willReturn($result);
            if ($event instanceof ProductSearchResultEvent) {
                $this->subscriber->onSearch($event);
            } else {
                $this->subscriber->onSuggest($event);
            }
        }
        $value = Signals::minimized($request, 200, true);
        self::assertSame('search', $value['stage']);
        self::assertSame(Failure::SearchEmpty->value, $value['failure_mask']);
        self::assertSame(Signals::KNOWN_SEARCH | Signals::KNOWN_HTTP, $value['known_mask']);
        self::assertStringNotContainsString('private', json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function testEsiAndUnrelatedSubrequestsCannotModifyTheMainJourney(): void
    {
        $main = $this->main('frontend.home.page', '/');
        $child = $main->duplicate(attributes: ['_route' => 'store-api.search', '_sw_esi' => true]);
        $this->stack->push($child);
        $result = $this->createMock(ProductListingResult::class);
        $result->method('getTotal')->willReturn(0);
        $event = new ProductSearchResultEvent($child, $result, $this->createMock(SalesChannelContext::class));
        $this->subscriber->onSearch($event);
        self::assertSame(0, Signals::minimized($main, 200, true)['failure_mask']);
        $child->attributes->remove('_sw_esi');
        $this->subscriber->onSearch($event);
        self::assertSame(Signals::KNOWN_HTTP, Signals::minimized($main, 200, true)['known_mask']);
    }

    public function testCheckoutInternalForwardPreservesFormFailureEvenWhenResponseIs200(): void
    {
        $main = $this->main('frontend.checkout.finish.order', '/checkout/order');
        $child = $main->duplicate(attributes: ['_route' => 'frontend.checkout.confirm.page', 'formViolations' => new ConstraintViolationException(new ConstraintViolationList(), ['email' => 'private@example.test'])]);
        $this->stack->push($child);
        $page = new CheckoutConfirmPage();
        $page->setCart(new Cart('private-cart-token'));
        $event = new CheckoutConfirmPageLoadedEvent($page, $this->createMock(SalesChannelContext::class), $child);
        $this->subscriber->onCheckout($event);
        $value = Signals::minimized($main, 200, true);
        self::assertSame('checkout', $value['stage']);
        self::assertSame(Failure::CheckoutForm->value, $value['failure_mask']);
        self::assertSame(Signals::KNOWN_CHECKOUT, $value['known_mask'] & Signals::KNOWN_CHECKOUT);
        self::assertStringNotContainsString('private', json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function testProductAvailabilityIsBooleanAndVariantParentsRemainUnknown(): void
    {
        $request = $this->main('store-api.product.detail', '/store-api/product/' . str_repeat('a', 32));
        $product = $this->product();
        $this->subscriber->recordProduct($request, $product);
        $value = Signals::minimized($request, 200, true);
        self::assertSame(Failure::ProductUnavailable->value | Failure::ProductOutOfStock->value, $value['failure_mask']);
        self::assertSame(Signals::KNOWN_PRODUCT | Signals::KNOWN_HTTP, $value['known_mask']);
        Signals::initialize($request);
        $product->setChildCount(3);
        $this->subscriber->recordProduct($request, $product);
        self::assertSame(Signals::KNOWN_HTTP, Signals::minimized($request, 200, true)['known_mask']);
    }

    public function testResolvedStockWarningsAreNotFailuresAndCartErrorsKeepNoMessage(): void
    {
        $request = $this->main('store-api.checkout.cart.add', '/store-api/checkout/cart/line-item');
        $cart = new Cart('private-cart-token');
        $cart->getErrors()->add(new ProductStockReachedError('private-product-id', 'private-name', 3, true));
        $signals = Signals::read($request);
        self::assertNotNull($signals);
        $signals->cart($cart);
        self::assertSame(0, Signals::minimized($request, 200, true)['failure_mask']);
        $cart->getErrors()->add(new ProductOutOfStockError('private-product-id', 'private-name'));
        $signals->cart($cart);
        $value = Signals::minimized($request, 200, true);
        self::assertSame(Failure::CartError->value | Failure::ProductUnavailable->value | Failure::ProductOutOfStock->value, $value['failure_mask']);
        self::assertStringNotContainsString('private', json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function testOrderIdIsRetainedOnlyForNonHumanObservationAndNeverReadFromFinishUrl(): void
    {
        $request = $this->main('store-api.checkout.cart.order', '/store-api/checkout/order');
        $id = str_repeat('b', 32);
        $event = $this->createMock(CheckoutOrderPlacedEvent::class);
        $event->method('getOrderId')->willReturn($id);
        $event->expects(self::never())->method('getCustomerId');
        $event->expects(self::never())->method('getOrder');
        $this->subscriber->onOrder($event);
        $classification = new Classification('test', 'test', Kind::BrowsingAgent, Evidence::Ip);
        $row = (new Minimizer())->minimize($request, $classification, str_repeat('k', 32), new \DateTimeImmutable('2026-10-14T12:00:00Z'), 200);
        self::assertSame('order', $row['stage']);
        self::assertSame(hex2bin($id), $row['order_id']);
        self::assertSame(0, $row['known_mask'] & Signals::KNOWN_PAYMENT);
        $human = (new Minimizer())->minimize($request, new Classification(), str_repeat('k', 32), new \DateTimeImmutable('2026-10-14T12:00:00Z'), 200);
        self::assertNull($human['order_id']);
        self::assertSame('', $human['stage']);
        self::assertSame(0, $human['known_mask']);
        $finish = Request::create('/checkout/finish?orderId=' . $id);
        self::assertNull(Signals::minimized($finish, 200, true)['order_id']);
        self::assertSame('checkout', Signals::minimized($finish, 200, true)['stage']);
    }

    public function testTruncatedCartInspectionDoesNotClaimAKnownSuccessfulOutcome(): void
    {
        $request = $this->main('store-api.checkout.cart.add', '/store-api/checkout/cart/line-item');
        $cart = new Cart('private-token');
        for ($index = 0; $index < 101; ++$index) {
            $cart->getErrors()->add(new ProductStockReachedError('private-' . $index, 'private-name', 3, true));
        }
        $signals = Signals::read($request);
        self::assertNotNull($signals);
        $signals->cart($cart);
        $value = Signals::minimized($request, 200, true);
        self::assertSame(0, $value['failure_mask']);
        self::assertSame(Signals::KNOWN_HTTP, $value['known_mask']);
    }

    public function testPaymentFailureDoesNotImplyOrderSuccessAndHttpFailuresRemainSeparate(): void
    {
        $request = $this->main('frontend.checkout.finish.page', '/checkout/finish');
        $request->attributes->set('paymentFailed', true);
        $event = $this->createMock(CheckoutFinishPageLoadedEvent::class);
        $event->method('getRequest')->willReturn($request);
        $this->subscriber->onFinish($event);
        $value = Signals::minimized($request, 403, true);
        self::assertSame('checkout', $value['stage']);
        self::assertNull($value['order_id']);
        self::assertSame(Failure::PaymentError->value | Failure::Http4xx->value | Failure::Forbidden->value, $value['failure_mask']);
        self::assertSame(Signals::KNOWN_PAYMENT | Signals::KNOWN_HTTP, $value['known_mask']);
    }

    public function testCheckoutValidationExceptionKeepsNoViolationsOrRequestData(): void
    {
        $request = $this->main('store-api.checkout.cart.order', '/store-api/checkout/order');
        $error = new ConstraintViolationException(new ConstraintViolationList(), ['email' => 'private@example.test']);
        $this->subscriber->onException(new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $error));
        $value = Signals::minimized($request, 400, true);
        self::assertSame(Failure::CheckoutForm->value | Failure::Http4xx->value, $value['failure_mask']);
        self::assertStringNotContainsString('private', json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function testProductDecoratorPreservesResponseAndOriginalExceptionAndFailsOpen(): void
    {
        $request = $this->main('store-api.product.detail', '/store-api/product/' . str_repeat('a', 32));
        $context = $this->createMock(SalesChannelContext::class);
        $response = new ProductDetailRouteResponse($this->product(), null);
        $inner = $this->createMock(AbstractProductDetailRoute::class);
        $inner->method('load')->willReturn($response);
        $decorator = new ProductDetailRouteDecorator($inner, $this->subscriber);
        self::assertSame($response, $decorator->load(str_repeat('a', 32), $request, $context, new Criteria()));
        $badResponse = new ProductDetailRouteResponse(new SalesChannelProductEntity(), null);
        $badInner = $this->createMock(AbstractProductDetailRoute::class);
        $badInner->method('load')->willReturn($badResponse);
        self::assertSame($badResponse, (new ProductDetailRouteDecorator($badInner, $this->subscriber))->load(str_repeat('a', 32), $request, $context, new Criteria()));
        $failed = $this->createMock(AbstractProductDetailRoute::class);
        $error = new \RuntimeException('original shop failure');
        $failed->method('load')->willThrowException($error);
        try {
            (new ProductDetailRouteDecorator($failed, $this->subscriber))->load(str_repeat('a', 32), $request, $context, new Criteria());
            self::fail('Original exception was swallowed');
        } catch (\RuntimeException $actual) {
            self::assertSame($error, $actual);
        }
    }

    private function main(string $route, string $path): Request
    {
        $request = Request::create($path, 'POST', content: 'private-body');
        $request->attributes->set('_route', $route);
        Signals::initialize($request);
        $this->stack->push($request);
        return $request;
    }

    private function product(): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setChildCount(0);
        $product->setAvailable(false);
        $product->setCalculatedMaxPurchase(0);
        $product->setIsCloseout(true);
        $product->setStock(0);
        $product->setMinPurchase(1);
        return $product;
    }
}
