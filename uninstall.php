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

    // Migrator-tracked installed-version stamp. Without this entry,
    // re-installing the plugin would treat the install as an upgrade
    // from the last-recorded version and re-fire applicable migration
    // callbacks against a fresh database.
    'vcr_plugin_version',
];

foreach ($options as $option) {
    delete_option($option);
}

// Cashier-list cache. Stored as a transient so wp_options OR the
// configured object cache can hold it; delete_transient handles both.
delete_transient('vcr_cashiers_cache');

// CBA exchange-rate cache (Phase A.8 multi-currency). One transient per
// ISO code we ever cached. We don't know the full list at uninstall
// time, so iterate the standard short-list of currencies our merchants
// use; missing keys are no-ops.
foreach (['USD', 'EUR', 'RUB', 'GBP', 'JPY', 'AMD'] as $iso) {
    delete_transient('vcr_cba_rate_' . $iso);
}

// Cancel any pending Action Scheduler jobs in our group. Without this
// the AS tables retain orphan jobs that fire after the plugin is gone,
// get marked failed because the action callback is unregistered, and
// pile up indefinitely.
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('vcr_fiscalize_order', [], 'vcr');
    as_unschedule_all_actions('vcr_register_refund', [], 'vcr');
}

// NOTE: We deliberately do NOT delete `_vcr_*` postmeta / order meta.
// Armenian Tax Code Article 56 requires fiscal records to be retained
// for 5 years from year-end. Deleting on uninstall would breach that
// obligation. The order data (with its fiscal meta) outlives the
// plugin install — same trade-off as PrivacyHandler::eraseFor() makes.
// Merchants who genuinely need to wipe meta on uninstall can run a
// one-shot SQL or a targeted wp-cli command.
