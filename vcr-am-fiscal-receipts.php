<?php

declare(strict_types=1);

/**
 * Plugin Name:       VCR — Fiscal Receipts for Armenia (eHDM)
 * Plugin URI:        https://vcr.am
 * Description:       Issue Armenian fiscal receipts (eHDM) to the State Revenue Committee directly from WooCommerce orders. Multi-currency + refunds.
 * Version:           0.1.12
 * Requires at least: 6.7
 * Tested up to:      7.1
 * Requires PHP:      8.3
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.4
 * WC tested up to:   11.1
 * Author:            Blob Solutions
 * Author URI:        https://blob.am
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vcr-am-fiscal-receipts
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
const PLUGIN_VERSION = '0.1.12';

// Both autoloaders are required for runtime. `vendor-prefixed/` carries
// every production dependency (SDK + Guzzle + php-http/* + the PSR
// contracts) under our private namespace, so nothing this plugin bundles
// can collide with another plugin's copy of the same library. `vendor/`
// is left holding only Composer's own autoloader, and is required because
// that is what resolves this plugin's own `src/` classes. A partial
// install — typical of `composer install --no-scripts` or shipping a
// raw Git checkout without running Strauss — would silently load only
// the first and then fatal at runtime when the SDK is referenced.
//
// Composer's generated autoloaders open with a platform check that
// *throws* below PHP 8.3, and a throw at this point is a white screen on
// every page of the site. WordPress's `Requires PHP` header prevents
// activation on an old PHP, but not a host that moves an already-active
// site down a version. Check first and go inert instead.
//
// Variables prefixed `$vcr_*` to satisfy WordPress.NamingConventions.PrefixAllGlobals.
if (PHP_VERSION_ID < 80300) {
    $vcr_php_version = PHP_VERSION;
    add_action('admin_notices', static function () use ($vcr_php_version): void {
        echo '<div class="notice notice-error"><p><strong>VCR — Fiscal Receipts for Armenia</strong> needs PHP 8.3 or newer. This server runs PHP '
            . esc_html($vcr_php_version) . '.</p></div>';
    });

    return;
}

$vcr_autoload          = __DIR__ . '/vendor/autoload.php';
$vcr_prefixed_autoload = __DIR__ . '/vendor-prefixed/autoload.php';

$vcr_missing = [];
if (! file_exists($vcr_autoload)) {
    $vcr_missing[] = 'composer dependencies (run <code>composer install</code>)';
}
if (! file_exists($vcr_prefixed_autoload)) {
    $vcr_missing[] = 'scoped vendor (run <code>composer strauss</code>; auto-runs on <code>composer install</code>)';
}

if ($vcr_missing !== []) {
    // This is nearly always one story: GitHub's "Download ZIP" button, or
    // a git clone, uploaded instead of the release artefact. The source
    // tree excludes vendor/ and vendor-prefixed/ on purpose, so the plugin
    // activates and then cannot load a single dependency. Lead with what
    // the person reading it should do; the composer detail underneath is
    // for the rarer case of someone genuinely building from source.
    //
    // Deliberately untranslated: this runs before the text domain is
    // available, and WP 6.7+ flags translation calls made this early.
    $vcr_detail = implode(' and ', $vcr_missing);
    add_action('admin_notices', static function () use ($vcr_detail): void {
        echo '<div class="notice notice-error">'
            . '<p><strong>VCR — Fiscal Receipts for Armenia</strong> cannot start: this copy is the plugin\'s source code, not the installable plugin. '
            . 'Download <code>vcr-am-fiscal-receipts.zip</code> from <a href="'
            . esc_url('https://github.com/blob-am/vcr-am-woocommerce/releases/latest')
            . '">the latest release</a> and upload that under Plugins &rarr; Add New &rarr; Upload Plugin.</p>'
            . '<p>Building from source? Missing ' . wp_kses_post($vcr_detail) . '.</p>'
            . '</div>';
    });

    return;
}

require_once $vcr_autoload;
require_once $vcr_prefixed_autoload;

// Plugin lifecycle hooks. We register them here at file-scope so they
// fire even when WooCommerce is missing (e.g. an admin deactivates WC,
// then deactivates us — the plugin object's `onPluginsLoaded` short-
// circuits in that case but the deactivation cleanup must still run).
register_deactivation_hook(PLUGIN_FILE, [Plugin::class, 'onDeactivation']);

(new Plugin(PLUGIN_FILE, PLUGIN_VERSION))->boot();
