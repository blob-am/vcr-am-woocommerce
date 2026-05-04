<?php

declare(strict_types=1);

/**
 * Plugin Name:       VCR — Fiscal Receipts for Armenia (eHDM)
 * Plugin URI:        https://vcr.am
 * Description:       Issue fiscal receipts (eHDM) to the Armenian State Revenue Committee directly from WooCommerce orders. Multi-currency, refunds, customer-facing QR. Direct SRC integration — no third-party gateway.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Tested up to:      6.9
 * Requires PHP:      8.3
 * WC requires at least: 9.4
 * WC tested up to:   10.7
 * Author:            Blob Solutions
 * Author URI:        https://blob.am
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vcr
 * Domain Path:       /languages
 *
 * @package BlobSolutions\WooCommerceVcrAm
 */

namespace BlobSolutions\WooCommerceVcrAm;

if (! defined('ABSPATH')) {
    exit;
}

if (defined(__NAMESPACE__ . '\\PLUGIN_FILE')) {
    return;
}

const PLUGIN_FILE    = __FILE__;
const PLUGIN_VERSION = '0.1.0';

$autoload          = __DIR__ . '/vendor/autoload.php';
$prefixed_autoload = __DIR__ . '/vendor-prefixed/autoload.php';

if (! file_exists($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>VCR — Fiscal Receipts for Armenia</strong>: Composer dependencies are missing. Run <code>composer install</code> in the plugin directory.</p></div>';
    });

    return;
}

require_once $autoload;

if (file_exists($prefixed_autoload)) {
    require_once $prefixed_autoload;
}

(new Plugin(PLUGIN_FILE, PLUGIN_VERSION))->boot();
