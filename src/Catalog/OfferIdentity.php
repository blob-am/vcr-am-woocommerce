<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use WC_Product;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The external id a product is known by in the register's catalogue.
 *
 * The store's SKU is deliberately not that id. A SKU is optional in
 * WooCommerce, typed by hand, unique only while nobody switches off the
 * `wc_product_has_unique_sku` filter, and freed for the next product when one
 * is deleted -- so the same string can come to mean a different thing. The API
 * stores an offer once per external id and never rewrites it on a later
 * reference, so a recycled SKU would keep printing the first product's name on
 * receipts. The WooCommerce post id is never recycled, which is why both
 * first-party channel integrations key on it instead: Facebook sends
 * `wc_post_id_<id>`, Google for WooCommerce sends `gla_<id>`.
 *
 * A SKU still wins where the register already holds an offer under it -- see
 * {@see OfferBinding}, which adopts that row rather than minting a second one
 * for the same product.
 */
final class OfferIdentity
{
    /** Namespace for the ids this plugin mints, so they read as ours in VCR. */
    public const PREFIX = 'wc-';

    /**
     * The charset the API accepts for `externalId`. A SKU carrying a space, a
     * slash or a Cyrillic letter is refused there, so it cannot be an identity
     * even when the merchant has typed one -- the minted id is used instead.
     */
    private const EXTERNAL_ID_REGEX = '/^[a-zA-Z0-9._-]{1,255}$/';

    /** The id this plugin would create for the product. Stable for its life. */
    public static function mint(WC_Product $product): string
    {
        return self::PREFIX . $product->get_id();
    }

    /**
     * The product's SKU when it is usable as an external id, else null.
     * Only catalogue adoption may use this.
     */
    public static function adoptableSku(WC_Product $product): ?string
    {
        $sku = trim($product->get_sku());

        if ($sku === '' || preg_match(self::EXTERNAL_ID_REGEX, $sku) !== 1) {
            return null;
        }

        return $sku;
    }
}
