<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Journey;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Content\Product\Cart\ProductOutOfStockError;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Symfony\Component\HttpFoundation\Request;

/** Request-local typed facts only. No entity, body, error message or search term is retained. */
final class Signals
{
    public const ATTRIBUTE = '_agentenblick_journey';
    public const KNOWN_SEARCH = 1;
    public const KNOWN_PRODUCT = 2;
    public const KNOWN_CART = 4;
    public const KNOWN_CHECKOUT = 8;
    public const KNOWN_ORDER = 16;
    public const KNOWN_HTTP = 32;
    public const KNOWN_PAYMENT = 64;
    private ?Stage $stage = null;
    private int $failures = 0;
    private int $known = 0;
    private ?string $orderId = null;

    public static function initialize(Request $request): self
    {
        $signals = new self();
        $request->attributes->set(self::ATTRIBUTE, $signals);
        return $signals;
    }

    public static function read(Request $request): ?self
    {
        $signals = $request->attributes->get(self::ATTRIBUTE);
        return $signals instanceof self ? $signals : null;
    }

    public function stage(Stage $stage): void
    {
        if ($this->stage === null || $stage->bit() > $this->stage->bit()) {
            $this->stage = $stage;
        }
    }

    public function known(int $mask): void
    {
        $this->known |= $mask & 127;
    }

    public function fail(Failure $failure): void
    {
        $this->failures |= $failure->value;
    }

    public function search(int $total): void
    {
        $this->stage(Stage::Search);
        $this->known(self::KNOWN_SEARCH);
        if ($total === 0) {
            $this->fail(Failure::SearchEmpty);
        }
    }

    public function product(SalesChannelProductEntity $product): void
    {
        $this->stage(Stage::Product);
        // A variant parent needs a selection; this is not evidence of stock failure.
        if (($product->getChildCount() ?? 0) > 0) {
            return;
        }
        $unavailable = !$product->getAvailable() || $product->getCalculatedMaxPurchase() <= 0;
        $outOfStock = $product->getIsCloseout() && $product->getStock() < max(1, $product->getMinPurchase() ?? 1);
        $this->known(self::KNOWN_PRODUCT);
        if ($unavailable) {
            $this->fail(Failure::ProductUnavailable);
        }
        if ($outOfStock) {
            $this->fail(Failure::ProductOutOfStock);
        }
    }

    public function cart(Cart $cart): void
    {
        $seen = 0;
        foreach ($cart->getErrors() as $error) {
            if (++$seen > 100) {
                return;
            }
            // Resolved stock quantity warnings do not mean a product is unavailable.
            if ($error->getLevel() < Error::LEVEL_ERROR) {
                continue;
            }
            $this->fail(Failure::CartError);
            $this->known(self::KNOWN_CART);
            if ($error instanceof ProductOutOfStockError) {
                $this->known(self::KNOWN_PRODUCT);
                $this->fail(Failure::ProductOutOfStock);
                $this->fail(Failure::ProductUnavailable);
            }
        }
        $this->known(self::KNOWN_CART);
    }

    public function order(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/Di', $id)) {
            return;
        }
        $this->stage(Stage::Order);
        $this->known(self::KNOWN_ORDER);
        $this->orderId ??= strtolower($id);
    }

    public function merge(self $other): void
    {
        if ($other->stage !== null) {
            $this->stage($other->stage);
        }
        $this->known |= $other->known;
        $this->failures |= $other->failures;
        $this->orderId ??= $other->orderId;
    }

    /** @return array{stage: string, failure_mask: int, known_mask: int, order_id: ?string} */
    public static function minimized(Request $request, int $status, bool $nonHuman): array
    {
        if (!$nonHuman) {
            return ['stage' => '', 'failure_mask' => 0, 'known_mask' => 0, 'order_id' => null];
        }
        $signals = clone (self::read($request) ?? new self());
        $stage = Stage::fromRequest($request);
        if ($stage !== null) {
            $signals->stage($stage);
        }
        $signals->known(self::KNOWN_HTTP);
        if (Stage::isOrderAttempt($request)) {
            $signals->known(self::KNOWN_ORDER);
        }
        if ($status >= 400 && $status <= 499) {
            $signals->fail(Failure::Http4xx);
        } elseif ($status >= 500 && $status <= 599) {
            $signals->fail(Failure::Http5xx);
        }
        if ($status === 403) {
            $signals->fail(Failure::Forbidden);
        } elseif ($status === 429) {
            $signals->fail(Failure::RateLimited);
        }
        $orderId = $signals->orderId === null ? null : hex2bin($signals->orderId);
        return [
            'stage' => $signals->stage === null ? '' : $signals->stage->value, 'failure_mask' => $signals->failures,
            'known_mask' => $signals->known, 'order_id' => $orderId === false ? null : $orderId,
        ];
    }
}
