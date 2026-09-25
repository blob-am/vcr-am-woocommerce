<?php

/**
 * Test fixture: mark an order Completed, firing the status transition so
 * the plugin's OrderListener sees it.
 *
 * Usage: wp eval-file complete-order.php <orderId>
 *
 * No `declare(strict_types=1)` — see create-paid-order.php.
 */

if (! function_exists('wc_get_order')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

$orderId = isset($args[0]) ? (int) $args[0] : 0;

if ($orderId <= 0) {
    fwrite(STDERR, "Usage: wp eval-file complete-order.php <orderId>\n");
    exit(1);
}

$order = wc_get_order($orderId);

if (! $order instanceof WC_Order) {
    fwrite(STDERR, "Order #{$orderId} not found.\n");
    exit(1);
}

$order->update_status('completed', 'E2E test completion');

echo $order->get_status() . "\n";
