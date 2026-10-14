<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Journey;

use Symfony\Component\HttpFoundation\Request;

enum Stage: string
{
    case Discover = 'discover';
    case Browse = 'browse';
    case Search = 'search';
    case Product = 'product';
    case Cart = 'cart';
    case Checkout = 'checkout';
    case Order = 'order';

    public function bit(): int
    {
        return match ($this) {
            self::Discover => 1, self::Browse => 2, self::Search => 4,
            self::Product => 8, self::Cart => 16, self::Checkout => 32, self::Order => 64,
        };
    }

    /** Route/path recognition never examines query strings or request bodies. */
    public static function fromRequest(Request $request): ?self
    {
        $route = $request->attributes->get('_route');
        $known = match ($route) {
            'frontend.home.page', 'frontend.navigation.page', 'store-api.product.listing' => self::Browse,
            'frontend.search.page', 'frontend.search.suggest', 'store-api.search', 'store-api.search.suggest' => self::Search,
            'frontend.detail.page', 'store-api.product.detail' => self::Product,
            'frontend.checkout.cart.page', 'frontend.checkout.cart.json', 'frontend.cart.offcanvas',
            'frontend.checkout.line-item.add', 'frontend.checkout.product.add-by-number',
            'frontend.checkout.line-item.change-quantity', 'frontend.checkout.line-items.update',
            'store-api.checkout.cart.add', 'store-api.checkout.cart.update', 'store-api.checkout.cart.read' => self::Cart,
            'frontend.checkout.confirm.page', 'frontend.checkout.register.page', 'frontend.checkout.finish.order',
            'frontend.checkout.finish.page', 'store-api.checkout.cart.order' => self::Checkout,
            default => null,
        };
        if ($known !== null) {
            return $known;
        }
        $path = $request->getPathInfo();
        if (preg_match('~^/(?:robots\.txt|llms(?:-full)?\.txt|agents\.md|sitemap(?:[-_][^/]*)?\.xml(?:\.gz)?)$~iD', $path) || str_starts_with($path, '/.well-known/')) {
            return self::Discover;
        }
        // These fixed shapes also work when a fresh HTTP-cache hit skips routing.
        return match (true) {
            $path === '/' => self::Browse,
            $path === '/store-api/_mcp' || preg_match('~^/store-api/product-listing/[a-f0-9]{32}/?$~D', $path) === 1 => self::Browse,
            in_array($path, ['/search', '/suggest', '/store-api/search', '/store-api/search-suggest'], true) => self::Search,
            preg_match('~^/(?:detail|store-api/product)/[a-f0-9]{32}/?$~D', $path) === 1 => self::Product,
            in_array($path, ['/checkout/cart', '/checkout/line-item/add', '/store-api/checkout/cart', '/store-api/checkout/cart/line-item'], true) => self::Cart,
            in_array($path, ['/checkout/confirm', '/checkout/register', '/checkout/order', '/checkout/finish', '/store-api/checkout/order'], true) => self::Checkout,
            preg_match('~^/ucp/v1/(?:catalog|catalog/search)/?$~D', $path) === 1 => self::Browse,
            preg_match('~^/ucp/v1/products/[^/]+/?$~D', $path) === 1 => self::Product,
            preg_match('~^/ucp/v1/carts(?:/[^/]+)?/?$~D', $path) === 1 => self::Cart,
            preg_match('~^/ucp/v1/checkout-sessions(?:/[^/]+)?/?$~D', $path) === 1 => self::Checkout,
            default => null,
        };
    }

    public static function isOrderAttempt(Request $request): bool
    {
        return in_array($request->attributes->get('_route'), ['frontend.checkout.finish.order', 'store-api.checkout.cart.order'], true)
            || ($request->isMethod('POST') && in_array($request->getPathInfo(), ['/checkout/order', '/store-api/checkout/order'], true));
    }
}
