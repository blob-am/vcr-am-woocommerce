<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

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
    ) {
    }

    public function register(): void
    {
        add_filter('woocommerce_get_settings_pages', [$this, 'addTab']);
    }

    /**
     * @param  array<int, mixed>  $pages
     * @return array<int, mixed>
     */
    public function addTab(array $pages): array
    {
        $pages[] = new VcrSettingsTab($this->keyStore);

        return $pages;
    }
}
