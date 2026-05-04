<?php

declare(strict_types=1);

/**
 * E2E test bootstrap (mu-plugin).
 *
 * Loaded into wp-env via the `mappings` block in `.wp-env.json`. Runs on
 * every WP request inside the test container. Two responsibilities:
 *
 *   1. Pre-configure the VCR plugin's settings to point at the mock VCR
 *      server (running on the host at 9876, reachable from the wp-env
 *      Docker container as `host.docker.internal`).
 *   2. Skip the configuration if a real VCR endpoint is set in env vars
 *      — lets a developer point at a staging instance for manual
 *      smoke-testing without the bootstrap stomping the values.
 *
 * Idempotent: only writes options that aren't already set with the same
 * value, so the WC settings page can override the bootstrap if a tester
 * needs to.
 *
 * @package BlobSolutions\WooCommerceVcrAm
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('init', static function (): void {
    $defaults = [
        'vcr_base_url' => getenv('VCR_E2E_BASE_URL') ?: 'http://host.docker.internal:9876/api/v1',
        'vcr_default_cashier_id' => getenv('VCR_E2E_CASHIER_ID') ?: '1',
        'vcr_default_department_id' => getenv('VCR_E2E_DEPARTMENT_ID') ?: '1',
        'vcr_test_mode' => 'no',
        'vcr_shipping_sku' => 'shipping',
        'vcr_fee_sku' => 'fee',
    ];

    foreach ($defaults as $option => $value) {
        if (get_option($option, null) !== $value) {
            update_option($option, $value, false);
        }
    }

    // KeyStore: write a fake encrypted-API-key cipher only if absent.
    // The plugin's KeyStore handles encryption via wp_salt('auth') —
    // we go through the proper put() so the ciphertext is valid for
    // this WP install's salt.
    if (get_option('vcr_api_key_encrypted', '') === '' && class_exists('BlobSolutions\\WooCommerceVcrAm\\Settings\\KeyStore')) {
        $keyStore = new BlobSolutions\WooCommerceVcrAm\Settings\KeyStore('vcr_api_key_encrypted');
        $keyStore->put('e2e-test-api-key');
    }
}, 99);
