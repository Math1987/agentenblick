<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Journey;

use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

final class ProductDetailRouteDecorator extends AbstractProductDetailRoute
{
    public function __construct(private readonly AbstractProductDetailRoute $inner, private readonly EventSubscriber $observer)
    {
    }

    public function getDecorated(): AbstractProductDetailRoute
    {
        return $this->inner;
    }

    public function load(string $productId, Request $request, SalesChannelContext $context, Criteria $criteria): ProductDetailRouteResponse
    {
        $response = $this->inner->load($productId, $request, $context, $criteria);
        $this->observer->recordProductResponse($request, $response);
        return $response;
    }
}
