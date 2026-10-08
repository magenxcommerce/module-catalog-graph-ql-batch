<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CatalogGraphQlBatch\Model\Resolver\Product;

use Magenx\CatalogGraphQlBatch\Model\ReviewStatsProvider;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Review\Model\Review\Config as ReviewsConfig;

/**
 * Resolves ProductInterface.review_count for a whole product list with one query.
 *
 * Replaces the core Magento_ReviewGraphQl resolver, which ran Review::getTotalReviews() per product:
 * one query for every card on a grid or linked-product list. Same values:
 * 0 when reviews are disabled or the product has none.
 */
class ReviewCount implements BatchResolverInterface
{
    /**
     * @param ReviewStatsProvider $provider
     * @param ReviewsConfig $reviewsConfig
     */
    public function __construct(
        private readonly ReviewStatsProvider $provider,
        private readonly ReviewsConfig $reviewsConfig
    ) {
    }

    /**
     * @param ContextInterface $context
     * @param Field $field
     * @param BatchRequestItemInterface[] $requests
     * @return BatchResponse
     */
    public function resolve(ContextInterface $context, Field $field, array $requests): BatchResponse
    {
        $response = new BatchResponse();

        $store = $context->getExtensionAttributes()?->getStore();
        if ($store === null || !$this->reviewsConfig->isEnabled()) {
            foreach ($requests as $request) {
                $response->addResponse($request, 0);
            }

            return $response;
        }

        $productIds = [];
        foreach ($requests as $key => $request) {
            $value = $request->getValue();
            $productIds[$key] = isset($value['model']) && $value['model'] instanceof ProductInterface
                ? (int) $value['model']->getId()
                : 0;
        }

        $values = $this->provider->getApprovedReviewCounts(
            (int) $store->getId(),
            array_values(array_unique(array_filter($productIds)))
        );

        foreach ($requests as $key => $request) {
            $response->addResponse($request, $values[$productIds[$key]] ?? 0);
        }

        return $response;
    }
}
