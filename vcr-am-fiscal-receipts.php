<?php

declare(strict_types=1);

/**
 * Plugin Name:       VCR — Fiscal Receipts for Armenia (eHDM)
 * Plugin URI:        https://vcr.am
 * Description:       Issue Armenian fiscal receipts (eHDM) to the State Revenue Committee directly from WooCommerce orders. Multi-currency + refunds.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Tested up to:      6.8
 * Requires PHP:      8.3
 * Requires Plugins:  woocommerce
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

// Both autoloaders are required for runtime. `vendor/` carries the
// non-scoped composer artefacts (PSR contracts left unprefixed for
// interop, Composer autoloader). `vendor-prefixed/` carries the
// production deps (SDK + Guzzle + php-http/*) under our private
// namespace so they don't conflict with other plugins. A partial
// install — typical of `composer install --no-scripts` or shipping a
// raw Git checkout without running Strauss — would silently load only
// the first and then fatal at runtime when the SDK is referenced.
$missing = [];
if (! file_exists($autoload)) {
    $missing[] = 'composer dependencies (run <code>composer install</code>)';
}
if (! file_exists($prefixed_autoload)) {
    $missing[] = 'scoped vendor (run <code>composer strauss</code>; auto-runs on <code>composer install</code>)';
}

if ($missing !== []) {
    $list = implode(' and ', $missing);
    add_action('admin_notices', static function () use ($list): void {
        echo '<div class="notice notice-error"><p><strong>VCR — Fiscal Receipts for Armenia</strong>: missing ' . wp_kses_post($list) . '.</p></div>';
    });

    return;
}

require_once $autoload;
require_once $prefixed_autoload;

// Plugin lifecycle hooks. We register them here at file-scope so they
// fire even when WooCommerce is missing (e.g. an admin deactivates WC,
// then deactivates us — the plugin object's `onPluginsLoaded` short-
// circuits in that case but the deactivation cleanup must still run).
register_deactivation_hook(PLUGIN_FILE, [Plugin::class, 'onDeactivation']);

(new Plugin(PLUGIN_FILE, PLUGIN_VERSION))->boot();
