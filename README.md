# Magenx_CatalogGraphQlBatch

Removes per-product database lookups from Magento's GraphQL product lists.
There is no schema change for the storefront and no configuration: enable the
module and grids and linked-product lists run fewer queries.

## Why

Profiling `GetProductLinkedProducts` (a PDP's related, upsell and
frequently-bought-together lists, about 24 products) showed these core paths
running once **per product**:

| Field | Core path | Per product |
|---|---|---|
| `price_range` | `CatalogRulePrice::getValue()` → `Rule::getRulePrice()` (the GraphQL product collection never joins `catalogrule_product_price`) | 1 query |
| `price_range` | `TierPrice::getStoredTierPrices()` → tier_price backend `afterLoad()` (the collection never calls `addTierPriceData()`) | 1 query |
| `rating_summary` | `Magento\ReviewGraphQl\...\RatingSummary`: `Summary::load($productId)` | 1 query |
| `review_count` | `Magento\ReviewGraphQl\...\ReviewCount`: `Review::getTotalReviews($productId)` | 1 query |

## What it does

- **`Plugin\PreloadProductPrices`** (graphql area, after
  `CatalogGraphQl\...\Product\CollectionPostProcessor::process()`): when a
  price field (`price_range`, `price`, `price_tiers`, `deal`) is selected, it
  loads tier prices for the whole collection (`addTierPriceData()`) and
  catalog rule prices with one `catalogrule_product_price` query, and sets
  them on each product. The pricing classes then read them from the model
  instead of querying. This post-processor runs for both GraphQL product
  loaders: the `products` query and the related/upsell/cross-sell lists.
- **`rating_summary` / `review_count`** are re-declared in `schema.graphqls`
  with batch resolvers (`Model\Resolver\Product\*`): one query per product
  list for each, through `Model\ReviewStatsProvider`.

The values are the same as core's. The pre-load uses the same inputs as the
per-product paths (current store and website, the store's local date, the
customer session's group), and the review queries use the same tables and
filters (`review_entity_summary` by store; approved reviews in
`review` + `review_store`). If the price pre-load fails, it logs a warning and
the pricing classes fall back to their own per-product lookups.

Not covered: configurable products still resolve their lowest-priced child per
product (`LowestPriceOptionsProvider`), logged-in customers' rule prices are
still recomputed per product by `CatalogRuleGraphQl`'s `UpdateCatalogRulePrice`
plugin, and `stock_status` is unchanged.

## Install

```bash
composer require magenxcommerce/module-catalog-graph-ql-batch
bin/magento module:enable Magenx_CatalogGraphQlBatch
bin/magento setup:upgrade && bin/magento setup:di:compile
```

## Verify

```bash
bin/magento dev:query-log:enable --include-all-queries=true --query-time-threshold=0 --include-call-stack=false
: > var/debug/db.log
# run one GetProductLinkedProducts (or a category grid) request
grep -oE 'FROM `[a-z0-9_]+`' var/debug/db.log | sort | uniq -c | sort -rn | head -20
bin/magento dev:query-log:disable
```

`catalogrule_product_price`, `catalog_product_entity_tier_price`,
`review_entity_summary` and `review` should appear once per product list, not
once per product. Compare a few `price_range`, `rating_summary` and
`review_count` values with the module disabled.
