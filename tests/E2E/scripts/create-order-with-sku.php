<?php

/**
 * Test fixture: create a product under a given SKU and a paid order for it,
 * then echo "<orderId> <productId>".
 *
 * Usage: wp eval-file create-order-with-sku.php <sku> [price]
 *
 * Separate from create-paid-order.php because the catalog tests need the
 * product id (the plugin mints its external id from it) and need to choose a
 * SKU the mock register does not carry. Same status transition as that
 * fixture: `processing`, so the plugin's order hook fires.
 *
 * No `declare(strict_types=1)` because wp-cli's eval-file wraps the file body
 * in `eval()`, where it isn't a valid first statement.
 */

if (! function_exists('wc_get_product')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

$sku = isset($args[0]) && $args[0] !== '' ? (string) $args[0] : 'E2E-NEW-1';
$price = isset($args[1]) && $args[1] !== '' ? (string) $args[1] : '1000';

$existingId = wc_get_product_id_by_sku($sku);
if ($existingId > 0) {
    $product = wc_get_product($existingId);
} else {
    $product = new WC_Product_Simple();
    $product->set_name('E2E product ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);
    $product->set_price($price);
    $product->save();
}

if (! $product instanceof WC_Product) {
    fwrite(STDERR, "Could not create the product.\n");
    exit(1);
}

$order = wc_create_order();
$order->add_product($product, 1);
$order->set_payment_method('bacs');
$order->calculate_totals();
$order->save();
$order->update_status('processing');

echo $order->get_id() . ' ' . $product->get_id();
