<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog\Coverage;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use WC_Product;
use WC_Product_Variable;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Every catalog reference this store could put on a receipt.
 *
 * One place answers "what does this store reference", so the coverage
 * check cannot disagree with the fiscal path about what counts. Two rules
 * are copied from {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\ItemBuilder}
 * on purpose:
 *
 *  - the SKU is read with `get_sku()` and `trim()`ed, with no case folding,
 *    because that is the exact string the receipt line will carry. Any
 *    normalisation here would report a match that fails at fiscalisation.
 *  - a variation is its own reference. `get_sku()` on a variation falls
 *    back to the parent's SKU when the variation has none, which is what
 *    an order line for that variation will send.
 *
 * Grouped and external products are skipped: neither is ever an order line
 * of its own -- a grouped product's children are ordinary products this
 * query already returns, and an external product is a link off-site.
 *
 * Walking the store product by product is deliberate. It costs a query per
 * page and an object per variation, which is why this runs from WP-CLI and
 * an explicit button rather than on a page render.
 */
/**
 * Not declared `final` so unit tests can mock it in place of walking a
 * real product catalogue -- there's no production extension point.
 */
class StoreSkuReader
{
    /**
     * Products per query. Large enough that a mid-size store is a handful
     * of round trips, small enough that the objects for one page fit in a
     * default WordPress memory limit.
     */
    private const PAGE_SIZE = 200;

    /**
     * Statuses an order can actually reference. A draft or trashed product
     * cannot be bought, so a missing offer for one is not a finding.
     */
    private const ORDERABLE_STATUSES = ['publish', 'private'];

    /** Product types that never appear as an order line of their own. */
    private const CONTAINER_TYPES = ['grouped', 'external'];

    public function __construct(
        private readonly Configuration $configuration,
    ) {
    }

    /**
     * @return list<StoreSku>
     */
    public function read(): array
    {
        $references = $this->fromSettings();

        for ($page = 1;; $page++) {
            $products = wc_get_products([
                'limit' => self::PAGE_SIZE,
                'page' => $page,
                'status' => self::ORDERABLE_STATUSES,
                'return' => 'objects',
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);

            if (! is_array($products) || $products === []) {
                break;
            }

            foreach ($products as $product) {
                if (! $product instanceof WC_Product) {
                    continue;
                }

                foreach ($this->orderLineProducts($product) as $sellable) {
                    $references[] = $this->toReference($sellable);
                }
            }

            if (count($products) < self::PAGE_SIZE) {
                break;
            }
        }

        return $references;
    }

    /**
     * The shipping and fee SKUs are offers like any other, and an order
     * that charges for delivery fails exactly as hard when the offer
     * behind that SKU does not exist. A store with no such setting has
     * nothing to check -- {@see ItemBuilder} only reaches for them when
     * the order carries such a line.
     *
     * @return list<StoreSku>
     */
    private function fromSettings(): array
    {
        $references = [];

        $shipping = $this->configuration->shippingSku();
        if ($shipping !== null) {
            $references[] = new StoreSku(
                sku: $shipping,
                label: __('Shipping line (from settings)', 'vcr-am-fiscal-receipts'),
            );
        }

        $fee = $this->configuration->feeSku();
        if ($fee !== null) {
            $references[] = new StoreSku(
                sku: $fee,
                label: __('Fee line (from settings)', 'vcr-am-fiscal-receipts'),
            );
        }

        return $references;
    }

    /**
     * @return list<WC_Product>
     */
    private function orderLineProducts(WC_Product $product): array
    {
        if ($product instanceof WC_Product_Variable) {
            $variations = [];
            foreach ($product->get_children() as $childId) {
                if (! is_int($childId) && ! is_string($childId)) {
                    continue;
                }

                $variation = wc_get_product((int) $childId);
                if ($variation instanceof WC_Product) {
                    $variations[] = $variation;
                }
            }

            return $variations;
        }

        if (in_array($product->get_type(), self::CONTAINER_TYPES, true)) {
            return [];
        }

        return [$product];
    }

    private function toReference(WC_Product $product): StoreSku
    {
        return new StoreSku(
            sku: trim($product->get_sku()),
            label: $product->get_name(),
            productId: $product->get_id(),
        );
    }
}
