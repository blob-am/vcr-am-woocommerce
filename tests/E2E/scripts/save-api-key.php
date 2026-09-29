<?php

/**
 * Test fixture: save an API key through WooCommerce's real settings-save
 * path — `WC_Admin_Settings::save_fields()` with a POST-shaped array —
 * and report what came back.
 *
 * Exists to exercise the path a merchant takes when they paste their key
 * into WooCommerce → Settings → VCR and hit Save, including the
 * `woocommerce_admin_settings_sanitize_option_vcr_api_key` filter that
 * diverts the key into KeyStore's encrypted option.
 *
 * Echoes JSON: `{"stored": true|false, "roundTrip": "...", "error": "..."}`.
 *
 * Args: [api_key]
 */

$args = $args ?? [];
$apiKey = $args[0] ?? '';
if ($apiKey === '') {
    fwrite(STDERR, "no API key provided\n");
    exit(1);
}

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

$result = ['stored' => false, 'roundTrip' => null, 'error' => null];

try {
    // WC reads $_POST inside save_fields, and resolves the field list
    // through get_settings() — the same two steps WC_Settings_Page::save()
    // performs when an admin submits the form.
    $_POST['vcr_api_key'] = $apiKey;
    $page->save();

    $result['stored'] = get_option('vcr_api_key_encrypted', '') !== '';
} catch (Throwable $e) {
    $result['error'] = get_class($e) . ': ' . $e->getMessage();
    $result['stored'] = get_option('vcr_api_key_encrypted', '') !== '';

    echo wp_json_encode($result) . "\n";
    exit(0);
}

// Read it back the way every consumer does, through Configuration.
try {
    $config = new BlobSolutions\WooCommerceVcrAm\Configuration(
        new BlobSolutions\WooCommerceVcrAm\Settings\KeyStore('vcr_api_key_encrypted'),
    );
    $result['roundTrip'] = $config->apiKey();
} catch (Throwable $e) {
    $result['error'] = 'on read-back: ' . get_class($e) . ': ' . $e->getMessage();
}

echo wp_json_encode($result) . "\n";
