<?php

/**
 * Test fixture: drive the WC settings filter chain for `vcr_base_url`
 * with a malicious URL and check that:
 *   1. The sanitizeBaseUrlSave filter rejects it (returns empty
 *      string), and
 *   2. The wp_options row is NOT updated to the unsafe URL.
 *
 * Echoes JSON: `{"input": "...", "saved": "...", "blocked": true|false}`.
 *
 * Args: [malicious_url]
 */

$args = $args ?? [];
$candidate = $args[0] ?? '';
if ($candidate === '') {
    fwrite(STDERR, "no URL provided\n");
    exit(1);
}

// Snapshot the current saved value so we can detect any change.
$before = get_option('vcr_base_url', '');

// Apply the WC filter chain manually — this is what
// woocommerce_admin_settings_sanitize_option_vcr_base_url does on save.
$filtered = apply_filters(
    'woocommerce_admin_settings_sanitize_option_vcr_base_url',
    $candidate,
    [],
    $candidate,
);

// If the filter let it through (rejected = false), simulate the WC
// save by writing the option. If blocked, the filter returned ''.
if ($filtered !== '' && $filtered !== $before) {
    update_option('vcr_base_url', $filtered, false);
}

$after = get_option('vcr_base_url', '');

// Restore so subsequent tests aren't affected.
if ($before !== $after) {
    update_option('vcr_base_url', $before, false);
}

echo wp_json_encode([
    'input' => $candidate,
    'filtered' => $filtered,
    'saved' => $after,
    'blocked' => $filtered === '',
]) . "\n";
