<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('wp_salt')->justReturn(str_repeat('x', 64));
    Functions\when('get_option')->justReturn(null);
});

// Most of the meaningful behaviour — the actual `listCashiers()` round-trip
// and the dropdown shaping of the returned models — needs a real (or
// mocked) `VcrClient`. That mocking belongs in the Phase-3 integration
// suite (php-http/mock-client + WC bootstrap). What we can assert at the
// unit level: the cache short-circuits the API call when a transient is
// present, and the credentials guard returns an empty list cleanly.

it('returns an empty list when credentials are not configured', function (): void {
    Functions\when('get_transient')->justReturn(false);

    $catalog = new CashierCatalog(
        new Configuration(new KeyStore('vcr_x')),
        new VcrClientFactory(),
    );

    expect($catalog->list())->toBe([]);
});

it('returns the cached value verbatim when the transient is hot', function (): void {
    $cached = [1 => 'Alice (desk ABC123)', 2 => 'Bob (desk XYZ789)'];

    Functions\when('get_transient')->alias(fn (string $key): mixed => $cached);

    $catalog = new CashierCatalog(
        new Configuration(new KeyStore('vcr_x')),
        new VcrClientFactory(),
    );

    expect($catalog->list())->toBe($cached);
});

it('refresh deletes the cache transient', function (): void {
    $deleted = false;
    Functions\when('delete_transient')->alias(function (string $key) use (&$deleted): bool {
        if ($key === 'vcr_cashiers_cache') {
            $deleted = true;
        }

        return true;
    });

    $catalog = new CashierCatalog(
        new Configuration(new KeyStore('vcr_x')),
        new VcrClientFactory(),
    );
    $catalog->refresh();

    expect($deleted)->toBeTrue();
});
