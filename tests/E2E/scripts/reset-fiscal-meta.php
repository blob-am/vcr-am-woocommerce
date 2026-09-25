<?php

/**
 * Test fixture: strip every `_vcr_*` meta key from every order and refund.
 *
 * Runs between specs so leftover state from an earlier test cannot satisfy
 * a later assertion. Deleting from wp_postmeta directly (which this
 * replaces) is a no-op under HPOS, where the meta lives in
 * wp_wc_orders_meta — so the reset silently stopped resetting and the
 * suite went on passing.
 *
 * Action Scheduler is NOT touched here: its tables are its own regardless
 * of the order datastore, so the specs clear them with a plain DELETE.
 *
 * Echoes the number of orders it touched.
 *
 * No `declare(strict_types=1)` — see read-order-meta.php.
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

$touched = 0;

foreach ($orders as $order) {
    $removed = false;

    foreach ($order->get_meta_data() as $entry) {
        $key = (string) $entry->get_data()['key'];

        if (str_starts_with($key, '_vcr_')) {
            $order->delete_meta_data($key);
            $removed = true;
        }
    }

    if ($removed) {
        $order->save();
        $touched++;
    }
}

echo $touched . "\n";
