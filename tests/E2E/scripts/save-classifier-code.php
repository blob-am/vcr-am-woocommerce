<?php

/**
 * Test fixture: submit a classifier code through WooCommerce's real settings
 * save, `WC_Settings_Page::save()` reading $_POST, and report what is stored.
 *
 * The field is a select whose options hold only the stored code, so the
 * value WooCommerce would keep on its own is never the one just picked; what
 * ends up stored is decided by the plugin's sanitize filter alone. That seam
 * is what this drives.
 *
 * Echoes JSON: `{"stored": "<option value>", "error": null|"..."}`.
 *
 * Args: [code]  ("" clears it)
 */

$args = $args ?? [];
$code = $args[0] ?? '';

$page = null;
foreach (WC_Admin_Settings::get_settings_pages() as $candidate) {
    if ($candidate->get_id() === 'vcr') {
        $page = $candidate;
        break;
    }
}

if ($page === null) {
    fwrite(STDERR, "VCR settings page is not registered\n");
    exit(1);
}

$result = ['stored' => null, 'error' => null];

try {
    $_POST['vcr_catalog_classifier_code'] = $code;
    $page->save();
} catch (Throwable $e) {
    $result['error'] = get_class($e) . ': ' . $e->getMessage();
}

$result['stored'] = get_option('vcr_catalog_classifier_code', '');

echo wp_json_encode($result) . "\n";
