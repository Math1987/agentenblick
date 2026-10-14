<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Journey;

enum Failure: int
{
    case SearchEmpty = 1;
    case ProductUnavailable = 2;
    case ProductOutOfStock = 4;
    case Http4xx = 8;
    case Http5xx = 16;
    case Forbidden = 32;
    case RateLimited = 64;
    case CheckoutForm = 128;
    case CartError = 256;
    case PaymentError = 512;
    case AbandonedCart = 1024;
    case AbandonedCheckout = 2048;

    public function code(): string
    {
        return match ($this) {
            self::SearchEmpty => 'search_empty', self::ProductUnavailable => 'product_unavailable',
            self::ProductOutOfStock => 'product_out_of_stock', self::Http4xx => 'http_4xx', self::Http5xx => 'http_5xx',
            self::Forbidden => 'forbidden', self::RateLimited => 'rate_limited', self::CheckoutForm => 'checkout_form',
            self::CartError => 'cart_error', self::PaymentError => 'payment_error',
            self::AbandonedCart => 'abandoned_cart', self::AbandonedCheckout => 'abandoned_checkout',
        };
    }
}
