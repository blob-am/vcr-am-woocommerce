<?php

declare(strict_types=1);

/**
 * VCR — Fiscal Receipts for Armenia: clean uninstall.
 *
 * Removes every option this plugin has written to `wp_options`, including
 * the encrypted API key ciphertext stored by KeyStore. WP fires this file
 * when the admin clicks "Delete" on the Plugins screen — *not* on simple
 * deactivation, so existing installs that just deactivate temporarily are
 * unaffected.
 *
 * Multisite is intentionally not handled here — Phase-3 work that adds
 * site-scoped settings should extend this to iterate `wp_get_sites()` and
 * delete per-site rows.
 *
 * @package BlobSolutions\WooCommerceVcrAm
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    // KeyStore-managed encrypted ciphertext.
    'vcr_api_key_encrypted',

    // WC settings tab fields. `vcr_api_key` itself is always written empty
    // by the intercept filter, but we still delete the row to leave a
    // clean wp_options table.
    'vcr_api_key',
    'vcr_base_url',
    'vcr_test_mode',
    'vcr_default_cashier_id',
    'vcr_default_department_id',
    'vcr_shipping_sku',
    'vcr_fee_sku',
];

foreach ($options as $option) {
    delete_option($option);
}
