<?php

/**
 * Test fixture: takes an existing paid order id as a positional arg
 * (`wp eval-file create-full-refund.php <orderId>`) and creates a FULL
 * refund of it via wc_create_refund() — the same entry point the WC
 * admin "Refund" UI uses.
 *
 * Echoes the new refund id on stdout. Loaded by the E2E suite via
 * `wp eval-file create-full-refund.php <orderId>`. Positional args from
 * wp-cli land in the `$args` global inside eval-file scripts.
 *
 * No `declare(strict_types=1)` for the same reason as create-paid-order.php
 * (eval-file wraps the body in eval()).
 */

if (! function_exists('wc_create_refund')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

// `$args` is populated by wp-cli from positional args after the script path.
$orderId = isset($args[0]) ? (int) $args[0] : 0;

if ($orderId <= 0) {
    fwrite(STDERR, "Usage: wp eval-file create-full-refund.php <orderId>\n");
    exit(1);
}

$order = wc_get_order($orderId);
if (! $order instanceof WC_Order) {
    fwrite(STDERR, "Order #{$orderId} not found.\n");
    exit(1);
}

// Build the line_items map WC expects: each line item refunded at full
// quantity and full amount. This mirrors what the admin "refund all"
// button does.
$lineItems = [];
foreach ($order->get_items() as $itemId => $item) {
    $lineItems[$itemId] = [
        'qty' => $item->get_quantity(),
        'refund_total' => (float) $item->get_total(),
        'refund_tax' => [], // no per-tax breakdown in test fixture
    ];
}

$refund = wc_create_refund([
    'amount' => (float) $order->get_total(),
    'reason' => 'E2E test refund',
    'order_id' => $orderId,
    'line_items' => $lineItems,
    'refund_payment' => false, // no gateway round-trip — we're testing fiscal flow
    'restock_items' => false,
]);

if (is_wp_error($refund)) {
    fwrite(STDERR, 'wc_create_refund failed: ' . $refund->get_error_message() . "\n");
    exit(1);
}

echo $refund->get_id() . "\n";
