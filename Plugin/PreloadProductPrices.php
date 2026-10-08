<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CatalogGraphQlBatch\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product\CollectionPostProcessor;
use Magento\CatalogRule\Model\ResourceModel\Rule as RuleResource;
use Magento\CatalogRule\Pricing\Price\CatalogRulePrice;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads catalog rule and tier prices for a whole GraphQL product list at once.
 *
 * Neither is part of the product collection core builds for GraphQL, so
 * computing `price_range` made each product fetch both on its own:
 *  - CatalogRulePrice::getValue() calls Rule::getRulePrice() for the one
 *    product unless the model already carries `catalog_rule_price`;
 *  - TierPrice::getStoredTierPrices() runs the tier_price backend's
 *    afterLoad() for the one product unless the model carries `tier_price`.
 * That is two queries per product on every grid and linked-product list.
 *
 * Runs after the collection is loaded and fills both with one query each,
 * from the same sources the per-product paths use (current store and
 * website, the store's local date, the customer session's group), so the
 * prices are the same ones the pricing classes would have loaded. A product
 * without a rule price gets `catalog_rule_price` = null, which
 * CatalogRulePrice reads as "no rule", exactly like an empty lookup.
 *
 * Logged-in customers: CatalogRuleGraphQl's UpdateCatalogRulePrice plugin
 * still recomputes the rule price for the product's customer group, as
 * before. If anything here fails the lists are left as they were and the
 * pricing classes fall back to their per-product lookups.
 */
class PreloadProductPrices
{
    /**
     * Product fields whose resolvers compute rule-aware prices.
     */
    private const PRICE_FIELDS = ['price_range', 'price', 'price_tiers', 'deal'];

    /**
     * @param RuleResource $ruleResource
     * @param TimezoneInterface $timezone
     * @param StoreManagerInterface $storeManager
     * @param CustomerSession $customerSession
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RuleResource $ruleResource,
        private readonly TimezoneInterface $timezone,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Pre-load prices once the GraphQL product collection is loaded.
     *
     * @param CollectionPostProcessor $subject
     * @param Collection $result
     * @param Collection $collection
     * @param array $attributeNames
     * @param ContextInterface|null $context
     * @return Collection
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterProcess(
        CollectionPostProcessor $subject,
        Collection $result,
        Collection $collection,
        array $attributeNames,
        ?ContextInterface $context = null
    ): Collection {
        if (!array_intersect(self::PRICE_FIELDS, $attributeNames) || $result->count() === 0) {
            return $result;
        }

        try {
            $result->addTierPriceData();
            $this->addCatalogRulePrices($result);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Magenx_CatalogGraphQlBatch: price pre-load skipped, prices load per product. ' . $e->getMessage()
            );
        }

        return $result;
    }

    /**
     * Set `catalog_rule_price` on every item from one catalogrule_product_price query.
     *
     * @param Collection $collection
     * @return void
     */
    private function addCatalogRulePrices(Collection $collection): void
    {
        $pending = [];
        foreach ($collection->getItems() as $product) {
            if (!$product->hasData(CatalogRulePrice::PRICE_CODE)) {
                $pending[(int) $product->getId()] = $product;
            }
        }
        if ($pending === []) {
            return;
        }

        $store = $this->storeManager->getStore();
        $prices = $this->ruleResource->getRulePrices(
            $this->timezone->scopeDate($store->getId()),
            $store->getWebsiteId(),
            $this->customerSession->getCustomerGroupId(),
            array_keys($pending)
        );

        foreach ($pending as $productId => $product) {
            $product->setData(CatalogRulePrice::PRICE_CODE, $prices[$productId] ?? null);
        }
    }
}
