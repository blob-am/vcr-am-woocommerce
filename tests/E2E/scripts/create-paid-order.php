<?php

/**
 * Test fixture: create a product with SKU + an order containing it,
 * then transition the order to "processing" so the WC payment hooks
 * fire and the VCR plugin enqueues a fiscal job.
 *
 * Echoes the new order id on stdout. Loaded by the E2E suite via
 * `wp eval-file`, executed inside the wp-env CLI container.
 *
 * No `declare(strict_types=1)` because wp-cli's eval-file wraps the
 * file body in `eval()`, where strict_types isn't a valid first
 * statement. The plugin code itself is strict.
 */

if (! function_exists('wc_get_product')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

$sku = $_ENV['VCR_E2E_SKU'] ?? 'E2E-SKU-1';
$price = $_ENV['VCR_E2E_PRICE'] ?? '1000';

// 1. Product (or reuse if it already exists for this SKU).
$existing = wc_get_product_id_by_sku($sku);
if ($existing > 0) {
    $product = wc_get_product($existing);
} else {
    $product = new WC_Product_Simple();
    $product->set_name('E2E Test Product ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);
    $product->set_manage_stock(false);
    $product->save();
}

// 2. Order with that product as a line item, in pending status.
$order = wc_create_order(['status' => 'pending']);
$order->add_product($product, 1);
$order->set_payment_method('bacs');           // any non-COD method → maps to nonCash
$order->calculate_totals();
$order->save();

// 3. Transition to processing — this is the moment our OrderListener
// hooks the payment_complete + status_processing actions.
$order->update_status('processing', 'E2E test transition');

echo $order->get_id() . "\n";
