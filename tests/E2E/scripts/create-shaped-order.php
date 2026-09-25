<?php

/**
 * Test fixture: create a paid order of a given shape and print it as JSON.
 *
 * Usage: wp eval-file create-shaped-order.php <shape> [paymentMethod]
 *
 * Shapes:
 *   rich          two products at different quantities, shipping, and a
 *                 positive fee. The ordinary shape of a real order, and the
 *                 one the suite otherwise never builds.
 *   negative-fee  the same, plus a negative fee line — how gift-card, loyalty
 *                 and store-credit extensions take money off a total.
 *                 WooCommerce settles on the reduced total, so a receipt
 *                 built from the item lines alone would overstate the sale.
 *                 The plugin must refuse this one.
 *
 * Prints `{"id":…,"total":"…","currency":"…"}` rather than the bare id: the
 * expected receipt total is whatever WooCommerce itself arrived at, and asking
 * for it separately would cost another container round-trip.
 *
 * No `declare(strict_types=1)` — see create-paid-order.php.
 */

if (! function_exists('wc_get_product')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

$shape = isset($args[0]) && $args[0] !== '' ? (string) $args[0] : 'rich';
$paymentMethod = isset($args[1]) && $args[1] !== '' ? (string) $args[1] : 'bacs';

if (! in_array($shape, ['rich', 'negative-fee'], true)) {
    fwrite(STDERR, "Unknown shape '{$shape}'.\n");
    exit(1);
}

/** Create or reuse a simple product. */
$product = static function (string $sku, string $price): WC_Product {
    $existing = wc_get_product_id_by_sku($sku);

    if ($existing > 0) {
        return wc_get_product($existing);
    }

    $product = new WC_Product_Simple();
    $product->set_name('E2E ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);
    $product->set_manage_stock(false);
    $product->save();

    return $product;
};

$order = wc_create_order(['status' => 'pending']);
$order->add_product($product('E2E-SHAPE-A', '1000'), 2);
$order->add_product($product('E2E-SHAPE-B', '250'), 3);

$shipping = new WC_Order_Item_Shipping();
$shipping->set_method_title('E2E Flat Rate');
$shipping->set_total('500');
$order->add_item($shipping);

$fee = new WC_Order_Item_Fee();
$fee->set_name('Handling');
$fee->set_total('300');
$order->add_item($fee);

if ($shape === 'negative-fee') {
    $discount = new WC_Order_Item_Fee();
    $discount->set_name('Gift card');
    $discount->set_total('-400');
    $order->add_item($discount);
}

$order->set_payment_method($paymentMethod);
$order->calculate_totals();
$order->save();

$order->update_status('processing', 'E2E test transition');

echo json_encode([
    'id' => $order->get_id(),
    'total' => $order->get_total(),
    'currency' => $order->get_currency(),
], JSON_UNESCAPED_SLASHES) . "\n";
