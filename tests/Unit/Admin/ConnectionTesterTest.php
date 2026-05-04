<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\ConnectionTester;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('wp_salt')->justReturn(str_repeat('x', 64));
    Functions\when('get_option')->justReturn(null);
});

it('registers the AJAX action and admin_enqueue_scripts hooks', function (): void {
    Actions\expectAdded('wp_ajax_vcr_test_connection')->once();
    Actions\expectAdded('admin_enqueue_scripts')->once();

    $keyStore = new KeyStore('vcr_test_keystore_option');
    (new ConnectionTester(
        $keyStore,
        new VcrClientFactory(),
        '/tmp/plugin.php',
        '0.1.0',
    ))->register();
});

// Exercising `handle()` would require a fully-mocked PSR-18 client that the
// vendor-prefixed VcrClient can discover. Defer the end-to-end test to the
// Phase-3 integration suite (php-http/mock-client + real WC bootstrap).
