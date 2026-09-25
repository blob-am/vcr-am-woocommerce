<?php

/**
 * Test fixture: permanently delete every order and refund.
 *
 * Only used when switching the authoritative order datastore. WooCommerce
 * refuses that switch outright while any order is out of sync between the
 * two tables ("The authoritative table for orders storage can't be changed
 * while there are orders out of sync"), and for a test store the cheap
 * answer is to have no orders rather than to migrate them.
 *
 * Echoes the number deleted.
 *
 * No `declare(strict_types=1)` — see create-paid-order.php.
 */

if (! function_exists('wc_get_orders')) {
    fwrite(STDERR, "WooCommerce isn't loaded — bailing.\n");
    exit(1);
}

$orders = wc_get_orders([
    'limit' => -1,
    'type' => ['shop_order', 'shop_order_refund'],
    'status' => 'any',
    'return' => 'objects',
]);

$deleted = 0;

foreach ($orders as $order) {
    $order->delete(true);
    $deleted++;
}

echo $deleted . "\n";
