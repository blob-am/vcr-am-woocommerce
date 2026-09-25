<?php

/**
 * Test fixture: print an order's (or refund's) `_vcr_*` meta as JSON.
 *
 * `wp post meta list` reads wp_postmeta, which holds nothing at all once a
 * store runs HPOS — the suite would then assert against an empty set and
 * report a pass having verified nothing. Going through wc_get_order() asks
 * WooCommerce for the order, so the same assertion holds on either
 * datastore, which is the point: both are live in the wild.
 *
 * Usage: wp eval-file read-order-meta.php <orderId>
 *
 * No `declare(strict_types=1)` because wp-cli's eval-file wraps the file
 * body in eval(), where strict_types isn't a valid first statement.
 */

if (! function_exists('wc_get_order')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

$orderId = isset($args[0]) ? (int) $args[0] : 0;

if ($orderId <= 0) {
    fwrite(STDERR, "Usage: wp eval-file read-order-meta.php <orderId>\n");
    exit(1);
}

$order = wc_get_order($orderId);

if (! $order instanceof WC_Abstract_Order) {
    fwrite(STDERR, "Order #{$orderId} not found.\n");
    exit(1);
}

$meta = [];

foreach ($order->get_meta_data() as $entry) {
    $data = $entry->get_data();
    $key = (string) $data['key'];

    if (str_starts_with($key, '_vcr_')) {
        $meta[$key] = $data['value'];
    }
}

echo json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
