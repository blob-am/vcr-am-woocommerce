<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

/**
 * Admin settings surface — lives under WooCommerce → Settings → VCR.
 *
 * Phase 1 scaffold: registers the menu entry and a placeholder render
 * callback. Real fields (API key, base URL, test mode, per-payment-method
 * fiscalization triggers) land in Phase 2 alongside the actual fiscal
 * flow they configure.
 */
final class SettingsPage
{
    public function register(): void
    {
        add_filter('woocommerce_get_settings_pages', [$this, 'addSettingsPage']);
    }

    /**
     * @param  array<int, mixed> $pages
     * @return array<int, mixed>
     */
    public function addSettingsPage(array $pages): array
    {
        // Wired in Phase 2 — returning the array unchanged for now keeps
        // the filter registration side-effect-free until the real settings
        // class exists.
        return $pages;
    }
}
