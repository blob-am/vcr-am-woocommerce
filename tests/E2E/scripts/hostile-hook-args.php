<?php

/**
 * Test fixture: fire the plugin's filters with the arguments another
 * plugin can realistically hand them, and report what comes back.
 *
 * A filter value is whatever the callback before us returned, and a
 * neighbouring plugin whose callback forgets its `return` passes null. A
 * typed parameter turns that into a TypeError on a plain wc-settings
 * load, with our name in the trace.
 *
 * Echoes JSON: `{"settingsPages": {...}, "baseUrl": ..., "apiKey": ...}`.
 */

$pages = apply_filters('woocommerce_get_settings_pages', null);

$tabIds = [];
if (is_array($pages)) {
    foreach ($pages as $page) {
        if ($page instanceof WC_Settings_Page) {
            $tabIds[] = $page->get_id();
        }
    }
}

echo wp_json_encode([
    'settingsPages' => [
        'isArray' => is_array($pages),
        'tabIds' => $tabIds,
    ],
    // null here means "field absent from the submission"; WC skips the
    // option entirely rather than overwriting it with the empty string.
    'baseUrl' => apply_filters(
        'woocommerce_admin_settings_sanitize_option_vcr_base_url',
        null,
        [],
        null,
    ),
    'apiKey' => apply_filters(
        'woocommerce_admin_settings_sanitize_option_vcr_api_key',
        null,
        [],
        null,
    ),
]) . "\n";
