<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Settings\VcrSettingsTab;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

beforeEach(function (): void {
    Functions\when('wp_salt')->justReturn(str_repeat('x', 64));
});

/**
 * Most of VcrSettingsTab's surface area (the get_settings render path)
 * needs a fully-booted WC for the field types and form-rendering
 * helpers. The two pure pieces of business logic — the API-key save
 * intercept and the cache-invalidation hook target — are testable in
 * isolation, and they're where bugs would silently corrupt user
 * configuration. Cover those here; defer the render path to the Phase-4
 * integration suite (wp-env + Playwright).
 */

it('settings tab title section description discloses the third-country data transfer', function (): void {
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('isSet')->andReturn(false);
    $keyStore->allows('get')->andReturn(null);

    $catalog = Mockery::mock(CashierCatalog::class);
    $catalog->allows('listForDropdown')->andReturn([]);
    $catalog->allows('list')->andReturn([]);

    Functions\when('wp_kses_post')->returnArg(1);

    $tab = new VcrSettingsTab($keyStore, $catalog);
    $settings = $tab->get_settings();

    // The title field at the top of the tab must carry a description
    // that names Armenia, the SCC mechanism, and what data is sent.
    // This is the merchant's primary GDPR notice surface — they have
    // to see it before they paste credentials.
    expect($settings)->toBeArray();
    expect($settings[0]['type'])->toBe('title');
    expect($settings[0]['desc'])
        ->toContain('Armenia')
        ->toContain('Standard Contractual Clauses')
        ->toContain('NOT transmitted')
        ->toContain('vcr.am/privacy');
});

it('interceptApiKeySave trims and persists the key into KeyStore', function (): void {
    $stored = null;
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->expects('put')->andReturnUsing(function (string $value) use (&$stored): void {
        $stored = $value;
    });

    $catalog = Mockery::mock(CashierCatalog::class);
    $tab = new VcrSettingsTab($keyStore, $catalog);

    $result = $tab->interceptApiKeySave("  fresh-key\n", [], "  fresh-key\n");

    // The intercept always returns the empty string so wp_options never
    // stores plaintext credentials — the encrypted ciphertext lives in
    // KeyStore's separate option.
    expect($result)->toBe('');
    // Whitespace from paste artifacts is stripped at the boundary so
    // the SRC API doesn't see "  key  \n" and reject every request.
    expect($stored)->toBe('fresh-key');
});

it('interceptApiKeySave skips KeyStore when the submitted value is empty', function (): void {
    // Empty submission is treated as "leave existing value alone" — the
    // common case where the admin opens settings without intending to
    // rotate the key.
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->expects('put')->never();

    $catalog = Mockery::mock(CashierCatalog::class);
    $tab = new VcrSettingsTab($keyStore, $catalog);

    expect($tab->interceptApiKeySave('', [], ''))->toBe('');
});

it('interceptApiKeySave skips KeyStore when the submitted value is pure whitespace', function (): void {
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->expects('put')->never();

    $catalog = Mockery::mock(CashierCatalog::class);
    $tab = new VcrSettingsTab($keyStore, $catalog);

    expect($tab->interceptApiKeySave("   \t\n", [], "   \t\n"))->toBe('');
});

it('interceptApiKeySave returns "" for non-string input without touching KeyStore', function (): void {
    // Defensive: WC's settings filter signature is loosely typed.
    // Anything that isn't a string is treated as "no submission".
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->expects('put')->never();

    $catalog = Mockery::mock(CashierCatalog::class);
    $tab = new VcrSettingsTab($keyStore, $catalog);

    expect($tab->interceptApiKeySave(null, [], ''))->toBe('')
        ->and($tab->interceptApiKeySave(false, [], ''))->toBe('')
        ->and($tab->interceptApiKeySave(['array'], [], ''))->toBe('');
});

it('invalidateCaches delegates to the cashier catalog refresh', function (): void {
    $keyStore = Mockery::mock(KeyStore::class);
    $catalog = Mockery::mock(CashierCatalog::class);
    $catalog->expects('refresh')->once();

    $tab = new VcrSettingsTab($keyStore, $catalog);
    $tab->invalidateCaches();
});

it('registers the API key sanitize-option filter and update-options action on construction', function (): void {
    Filters\expectAdded('woocommerce_admin_settings_sanitize_option_vcr_api_key')->once();
    \Brain\Monkey\Actions\expectAdded('woocommerce_update_options_vcr')->once();

    $keyStore = Mockery::mock(KeyStore::class);
    $catalog = Mockery::mock(CashierCatalog::class);

    new VcrSettingsTab($keyStore, $catalog);
});

it('exposes "vcr" as the WC settings page id', function (): void {
    $keyStore = Mockery::mock(KeyStore::class);
    $catalog = Mockery::mock(CashierCatalog::class);

    $tab = new VcrSettingsTab($keyStore, $catalog);

    expect($tab->id)->toBe('vcr');
});
