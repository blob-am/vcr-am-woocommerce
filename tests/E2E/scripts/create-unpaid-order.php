<?php

/**
 * Create an order the gateway never confirmed: it has a line and a payment
 * method, and stays "Pending payment". Prints its id.
 *
 * This is what a store sees when the bank takes the money and the gateway's
 * callback to WooCommerce never arrives.
 */

if (! function_exists('wc_get_product')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

$sku = 'E2E-SKU-1';

$existing = wc_get_product_id_by_sku($sku);
if ($existing > 0) {
    $product = wc_get_product($existing);
} else {
    $product = new WC_Product_Simple();
    $product->set_name('E2E Test Product ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price('1000');
    $product->set_manage_stock(false);
    $product->save();
}

$order = wc_create_order(['status' => 'pending']);
$order->add_product($product, 1);
$order->set_payment_method('bacs');
$order->set_billing_first_name('Anahit');
$order->set_billing_last_name('Sargsyan');
$order->set_billing_email('anahit.buyer@example.com');
$order->set_billing_phone('+37491234567');
$order->set_billing_address_1('12 Abovyan Street');
$order->calculate_totals();
$order->save();

echo $order->get_id() . "\n";
