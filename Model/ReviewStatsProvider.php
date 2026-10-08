<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CatalogGraphQlBatch\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Review\Model\Review;

/**
 * Rating summaries and approved review counts for many products in one query each.
 *
 * Same tables and filters as the per-product core reads it replaces:
 *  - Review\Summary::load($productId) with a store id: review_entity_summary
 *    by entity_pk_value + store_id;
 *  - Review::getTotalReviews($productId, true, $storeId): approved reviews in
 *    `review`, joined to `review_store` for the store.
 */
class ReviewStatsProvider
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Rating summary (0-100) per product id; products without a summary row are absent.
     *
     * @param int $storeId
     * @param int[] $productIds
     * @return array<int, float>
     */
    public function getRatingSummaries(int $storeId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('review_entity_summary'),
                ['entity_pk_value', 'rating_summary']
            )
            ->where('store_id = ?', $storeId)
            ->where('entity_pk_value IN (?)', $productIds, \Zend_Db::INT_TYPE)
            // Summary::load() takes the first row; keep that pick deterministic.
            ->order('primary_id ASC');

        $summaries = [];
        foreach ($connection->fetchAll($select) as $row) {
            $productId = (int) $row['entity_pk_value'];
            $summaries[$productId] ??= (float) $row['rating_summary'];
        }

        return $summaries;
    }

    /**
     * Approved review count per product id; products without reviews are absent.
     *
     * @param int $storeId
     * @param int[] $productIds
     * @return array<int, int>
     */
    public function getApprovedReviewCounts(int $storeId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['review' => $this->resource->getTableName('review')],
                ['entity_pk_value', 'review_count' => new \Zend_Db_Expr('COUNT(*)')]
            )
            ->where('review.entity_pk_value IN (?)', $productIds, \Zend_Db::INT_TYPE)
            ->where('review.status_id = ?', Review::STATUS_APPROVED)
            ->group('review.entity_pk_value');

        if ($storeId > 0) {
            $select->join(
                ['store' => $this->resource->getTableName('review_store')],
                $connection->quoteInto('review.review_id = store.review_id AND store.store_id = ?', $storeId),
                []
            );
        }

        return array_map('intval', $connection->fetchPairs($select));
    }
}
