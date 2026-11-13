<?php

/**
 * Test fixture: report a handful of the plugin's strings as WordPress
 * translates them for one locale.
 *
 * Nothing here loads a catalogue by hand. The plugin calls no
 * load_plugin_textdomain(), so every string below is only translated if
 * WordPress itself finds the compiled file the build put in /languages —
 * which is the whole claim under test.
 *
 * Usage: wp eval-file translated-strings.php <locale>
 *
 * Echoes JSON: `{"switched": bool, "receiptLink": "...", "cashiers":
 * {"1": "...", "3": "...", "5": "..."}, "description": "..."}`.
 */

$locale = isset($args[0]) && is_string($args[0]) ? $args[0] : '';

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$switched = switch_to_locale($locale);

$cashiers = [];
foreach ([1, 3, 5] as $count) {
    $cashiers[(string) $count] = sprintf(
        _n('%d cashier available.', '%d cashiers available.', $count, 'vcr-am-fiscal-receipts'),
        $count,
    );
}

$plugin = get_plugin_data(WP_PLUGIN_DIR . '/vcr-am-fiscal-receipts/vcr-am-fiscal-receipts.php', false, true);

echo wp_json_encode([
    'switched' => $switched,
    'receiptLink' => __('View your receipt', 'vcr-am-fiscal-receipts'),
    'cashiers' => $cashiers,
    'description' => $plugin['Description'],
]) . "\n";

if ($switched) {
    restore_previous_locale();
}
