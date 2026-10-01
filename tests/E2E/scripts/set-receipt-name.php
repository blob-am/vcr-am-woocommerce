<?php

/**
 * Test fixture: edit "Name on the fiscal receipt" on a product the way the
 * admin screen does, then echo the product id.
 *
 * Usage: wp eval-file set-receipt-name.php <product-id> [name]
 *
 * An empty name means "the merchant cleared the box", which is a different
 * answer from "the merchant did not touch it" -- so the field is always put in
 * $_POST, even blank. The value is slashed because WordPress hands $_POST
 * through add_magic_quotes and the field unslashes what it reads.
 *
 * Firing `woocommerce_process_product_meta` rather than writing the meta
 * directly is the point: writing the meta would skip the change detection and
 * the action that drives the catalog sync, which is what these specs are about.
 *
 * No `declare(strict_types=1)` because wp-cli's eval-file wraps the file body
 * in `eval()`, where it isn't a valid first statement.
 */

if (! class_exists(\BlobSolutions\WooCommerceVcrAm\Catalog\ReceiptName::class)) {
    fwrite(STDERR, "The plugin isn't loaded — bailing.\n");
    exit(1);
}

$productId = isset($args[0]) ? (int) $args[0] : 0;
$name = isset($args[1]) ? (string) $args[1] : '';

if ($productId <= 0) {
    fwrite(STDERR, "usage: set-receipt-name.php <product-id> [name]\n");
    exit(1);
}

$_POST[\BlobSolutions\WooCommerceVcrAm\Catalog\ReceiptName::META_KEY] = wp_slash($name);

do_action('woocommerce_process_product_meta', $productId);

echo $productId;
