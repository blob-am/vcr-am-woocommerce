<?php

/**
 * Test fixture: same as create-paid-order.php but ALSO sets
 * billing_email on the order so the GDPR exporter test can match
 * against it. Echoes the new order id on stdout.
 *
 * No `declare(strict_types=1)` because wp-cli's eval-file wraps the
 * file body in eval().
 */

if (! function_exists('wc_get_product')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

// wp-cli's `eval-file` populates $args with positional arguments
// passed after the file path. Order: [email, sku, price]. Each
// arg is optional with sensible defaults so the script works under
// both `wp eval-file path.php email@x.com` and bare invocation.
$args = $args ?? [];
$email = $args[0] ?? ($_ENV['VCR_E2E_EMAIL'] ?? 'gdpr-test@example.com');
$sku = $args[1] ?? ($_ENV['VCR_E2E_SKU'] ?? 'E2E-SKU-GDPR');
$price = $args[2] ?? ($_ENV['VCR_E2E_PRICE'] ?? '1000');

$existing = wc_get_product_id_by_sku($sku);
if ($existing > 0) {
    $product = wc_get_product($existing);
} else {
    $product = new WC_Product_Simple();
    $product->set_name('GDPR E2E Product ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);
    $product->set_manage_stock(false);
    $product->save();
}

$order = wc_create_order(['status' => 'pending']);
$order->add_product($product, 1);
$order->set_billing_email($email);
$order->set_payment_method('bacs');
$order->calculate_totals();
$order->save();

$order->update_status('processing', 'E2E GDPR test transition');

echo $order->get_id() . "\n";
