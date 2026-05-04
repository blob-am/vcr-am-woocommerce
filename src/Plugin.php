<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm;

use BlobSolutions\WooCommerceVcrAm\Settings\SettingsPage;

/**
 * Top-level plugin bootstrap.
 *
 * Wired from the plugin entry file (`vcr-am-fiscal-receipts.php`). Performs
 * three jobs:
 *
 *   1. Declares HPOS + Cart/Checkout Blocks compatibility on
 *      `before_woocommerce_init` (must run before WC's own bootstrap, hence
 *      the early hook).
 *   2. Validates that WooCommerce is active on `plugins_loaded`. If not,
 *      surfaces an admin notice instead of fatal-erroring on missing WC
 *      classes.
 *   3. Boots subordinate services (settings, hooks, REST endpoints) — only
 *      when the WC active check passes.
 *
 * Singletons / global state are deliberately avoided. The single instance
 * is held by the plugin entry file's local scope.
 */
final class Plugin
{
    private bool $booted = false;

    public function __construct(
        private readonly string $pluginFile,
        private readonly string $version,
    ) {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        add_action('before_woocommerce_init', [$this, 'declareWooCommerceCompatibility']);
        add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
    }

    /**
     * Tell WooCommerce we are HPOS-compatible (custom order tables, default
     * for new stores since WC 8.2) and Cart/Checkout Blocks compatible
     * (default since WC 8.3). Stores incompat on either declaration breaks
     * activation in modern WC.
     *
     * Guarded by `class_exists` so the plugin loads safely when WC is
     * absent (we surface a separate notice in that case from
     * `onPluginsLoaded`).
     */
    public function declareWooCommerceCompatibility(): void
    {
        $featuresUtil = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';

        if (! class_exists($featuresUtil)) {
            return;
        }

        $featuresUtil::declare_compatibility('custom_order_tables', $this->pluginFile, true);
        $featuresUtil::declare_compatibility('cart_checkout_blocks', $this->pluginFile, true);
    }

    public function onPluginsLoaded(): void
    {
        if (! class_exists('\\WooCommerce')) {
            add_action('admin_notices', [$this, 'showWooCommerceMissingNotice']);

            return;
        }

        load_plugin_textdomain(
            'vcr',
            false,
            dirname(plugin_basename($this->pluginFile)) . '/languages'
        );

        (new SettingsPage())->register();
    }

    public function showWooCommerceMissingNotice(): void
    {
        echo '<div class="notice notice-error"><p>';
        echo esc_html(
            __(
                'VCR — Fiscal Receipts for Armenia requires WooCommerce to be installed and active.',
                'vcr'
            )
        );
        echo '</p></div>';
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getPluginFile(): string
    {
        return $this->pluginFile;
    }
}
