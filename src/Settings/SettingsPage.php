<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Top-level registration entry for the plugin's settings tab.
 *
 * Lives in WooCommerce → Settings → VCR. The actual tab class
 * (`VcrSettingsTab`) extends `WC_Settings_Page` and is constructed lazily
 * inside `addTab()` so this class stays instantiable in unit tests
 * without WooCommerce loaded.
 */
final class SettingsPage
{
    public function __construct(
        private readonly KeyStore $keyStore,
        private readonly CashierCatalog $cashierCatalog,
        private readonly DepartmentCatalog $departmentCatalog,
        private readonly ConnectionProbe $probe,
        private readonly GeneralFields $general,
        private readonly AdvancedFields $advanced,
    ) {
    }

    public function register(): void
    {
        add_filter('woocommerce_get_settings_pages', [$this, 'addTab']);
    }

    /**
     * A filter value is whatever the callback before us returned. One
     * plugin whose `woocommerce_get_settings_pages` callback forgets to
     * return the array — a bare `return;` gives null — would turn an
     * `array` parameter here into a TypeError on every wc-settings load,
     * and the stack trace would name us for someone else's bug. Narrow
     * instead, the way every other filter callback in this plugin does.
     *
     * @param  mixed $pages
     * @return array<int, mixed>
     */
    public function addTab(mixed $pages): array
    {
        // array_values, not a bare cast: WooCommerce only ever iterates
        // this collection, and re-indexing is what keeps the list shape
        // the return type promises even if an upstream filter handed us
        // a keyed array.
        $pages = is_array($pages) ? array_values($pages) : [];

        $pages[] = new VcrSettingsTab(
            $this->keyStore,
            $this->cashierCatalog,
            $this->departmentCatalog,
            $this->probe,
            $this->general,
            $this->advanced,
        );

        return $pages;
    }
}
