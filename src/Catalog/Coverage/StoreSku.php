<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog\Coverage;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * One catalog reference this store would put on a receipt, and the name
 * the admin would recognise it by.
 *
 * `sku` is empty for a product that carries none. That is a finding, not a
 * missing value: {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\ItemBuilder}
 * refuses to build a receipt line without a SKU, so such a product blocks
 * its whole order. Keeping it in the same shape as a SKU that simply has
 * no offer lets one pass over the store collect both.
 *
 * `productId` is null when the reference comes from a setting rather than
 * a product — the shipping and fee SKUs are offers too, and an order that
 * charges for delivery fails exactly as hard when theirs is missing.
 */
final readonly class StoreSku
{
    public function __construct(
        public string $sku,
        public string $label,
        public ?int $productId = null,
    ) {
    }
}
