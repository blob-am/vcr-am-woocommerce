<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Settings\SettingsPage;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('wp_salt')->justReturn(str_repeat('x', 64));
    Functions\when('get_option')->justReturn(null);
});

it('hooks into woocommerce_get_settings_pages on register', function (): void {
    Filters\expectAdded('woocommerce_get_settings_pages')->once();

    $keyStore = new KeyStore('vcr_test_keystore_option');
    $config = new Configuration($keyStore);
    $catalog = new CashierCatalog($config, new VcrClientFactory());
    (new SettingsPage($keyStore, $catalog))->register();
});

// `VcrSettingsTab` extends `WC_Settings_Page` — exercising `addTab()` would
// require booting WooCommerce or shipping a stub for that base class.
// Deferred to the Phase-3 integration suite (wp-env + real WC) where the
// full settings render path can be asserted end-to-end.
