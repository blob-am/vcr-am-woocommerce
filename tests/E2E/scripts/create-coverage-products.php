<?php

/**
 * Test fixture: create the product shapes the catalog coverage check has to
 * get right against real WooCommerce, and print them as JSON.
 *
 * Usage: wp eval-file create-coverage-products.php
 *
 * Unit tests can only assert what a mocked `wc_get_products()` was told to
 * return. These are the four behaviours that depend on WooCommerce itself:
 *
 *   - a draft product is not orderable, so it must not be reported
 *   - an external product is never an order line, so it must not be reported
 *   - a variable product is read as its variations, not as itself
 *   - a variation with no SKU of its own reports its parent's, which is what
 *     an order line for it would send
 *
 * Idempotent: re-running reuses products by SKU rather than piling up
 * duplicates, because WooCommerce refuses a duplicate SKU anyway.
 *
 * No `declare(strict_types=1)` — see create-paid-order.php.
 */

if (! function_exists('wc_get_product')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

/** Create or reuse a simple product, optionally without a SKU. */
$simple = static function (?string $sku, string $name, string $status = 'publish') {
    if ($sku !== null) {
        $existing = wc_get_product_id_by_sku($sku);
        if ($existing > 0) {
            $product = wc_get_product($existing);
            $product->set_status($status);
            $product->save();

            return $product->get_id();
        }
    }

    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_status($status);
    $product->set_regular_price('1000');
    if ($sku !== null) {
        $product->set_sku($sku);
    }

    return $product->save();
};

$covered = $simple('E2E-COVERED', 'Covered product');
$uncovered = $simple('E2E-MISSING', 'Uncovered product');
$draft = $simple('E2E-DRAFT', 'Draft product', 'draft');

// A product with no SKU at all. Looked up by name, since there is no SKU to
// look it up by and WooCommerce is happy to hold many of them.
$noSku = 0;
foreach (wc_get_products(['limit' => -1, 'status' => ['publish'], 'return' => 'objects']) as $candidate) {
    if ($candidate->get_name() === 'No SKU product') {
        $noSku = $candidate->get_id();
        break;
    }
}
if ($noSku === 0) {
    $noSku = $simple(null, 'No SKU product');
}

// External/affiliate: has a SKU, is never an order line.
$externalId = wc_get_product_id_by_sku('E2E-EXTERNAL');
if ($externalId === 0) {
    $external = new WC_Product_External();
    $external->set_name('Affiliate product');
    $external->set_sku('E2E-EXTERNAL');
    $external->set_regular_price('1000');
    $external->set_product_url('https://example.com/buy');
    $externalId = $external->save();
}

// Variable parent with two variations: one with its own SKU, one without.
$parentId = wc_get_product_id_by_sku('E2E-PARENT');
if ($parentId === 0) {
    $attribute = new WC_Product_Attribute();
    $attribute->set_name('Size');
    $attribute->set_options(['Small', 'Large']);
    $attribute->set_visible(true);
    $attribute->set_variation(true);

    $parent = new WC_Product_Variable();
    $parent->set_name('Variable product');
    $parent->set_sku('E2E-PARENT');
    $parent->set_status('publish');
    $parent->set_attributes([$attribute]);
    $parentId = $parent->save();

    $withSku = new WC_Product_Variation();
    $withSku->set_parent_id($parentId);
    $withSku->set_attributes(['size' => 'Small']);
    $withSku->set_sku('E2E-VAR-A');
    $withSku->set_regular_price('1000');
    $withSku->save();

    $withoutSku = new WC_Product_Variation();
    $withoutSku->set_parent_id($parentId);
    $withoutSku->set_attributes(['size' => 'Large']);
    $withoutSku->set_regular_price('1000');
    $withoutSku->save();

    WC_Product_Variable::sync($parentId);
}

$parent = wc_get_product($parentId);

echo wp_json_encode([
    'covered' => $covered,
    'uncovered' => $uncovered,
    'draft' => $draft,
    'noSku' => $noSku,
    'external' => $externalId,
    'parent' => $parentId,
    'variations' => array_values($parent->get_children()),
]), "\n";
