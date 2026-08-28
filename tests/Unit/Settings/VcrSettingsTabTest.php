<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
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

/**
 * Most tests here don't care about the department dropdown; they just
 * need the constructor satisfied. The ones that do care build their own.
 */
function stubDepartmentCatalog(): DepartmentCatalog
{
    $catalog = Mockery::mock(DepartmentCatalog::class);
    $catalog->allows('list')->andReturn([]);
    $catalog->allows('refresh');

    return $catalog;
}

it('settings tab title section description discloses the third-country data transfer', function (): void {
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('isSet')->andReturn(false);
    $keyStore->allows('get')->andReturn(null);

    $catalog = Mockery::mock(CashierCatalog::class);
    $catalog->allows('listForDropdown')->andReturn([]);
    $catalog->allows('list')->andReturn([]);

    Functions\when('wp_kses_post')->returnArg(1);

    $tab = new VcrSettingsTab($keyStore, $catalog, stubDepartmentCatalog());
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
    $tab = new VcrSettingsTab($keyStore, $catalog, stubDepartmentCatalog());

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
    $tab = new VcrSettingsTab($keyStore, $catalog, stubDepartmentCatalog());

    expect($tab->interceptApiKeySave('', [], ''))->toBe('');
});

it('interceptApiKeySave skips KeyStore when the submitted value is pure whitespace', function (): void {
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->expects('put')->never();

    $catalog = Mockery::mock(CashierCatalog::class);
    $tab = new VcrSettingsTab($keyStore, $catalog, stubDepartmentCatalog());

    expect($tab->interceptApiKeySave("   \t\n", [], "   \t\n"))->toBe('');
});

it('interceptApiKeySave returns "" for non-string input without touching KeyStore', function (): void {
    // Defensive: WC's settings filter signature is loosely typed.
    // Anything that isn't a string is treated as "no submission".
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->expects('put')->never();

    $catalog = Mockery::mock(CashierCatalog::class);
    $tab = new VcrSettingsTab($keyStore, $catalog, stubDepartmentCatalog());

    expect($tab->interceptApiKeySave(null, [], ''))->toBe('')
        ->and($tab->interceptApiKeySave(false, [], ''))->toBe('')
        ->and($tab->interceptApiKeySave(['array'], [], ''))->toBe('');
});

it('invalidateCaches refreshes both catalogs', function (): void {
    // Both dropdowns are populated with the same API key, so a
    // credentials change has to drop both caches. Refreshing only the
    // cashier list leaves the department list serving up to an hour of
    // options belonging to the previous register.
    $keyStore = Mockery::mock(KeyStore::class);
    $catalog = Mockery::mock(CashierCatalog::class);
    $catalog->expects('refresh')->once();

    $departments = Mockery::mock(DepartmentCatalog::class);
    $departments->expects('refresh')->once();

    $tab = new VcrSettingsTab($keyStore, $catalog, $departments);
    $tab->invalidateCaches();
});

it('registers the API key sanitize-option filter and update-options action on construction', function (): void {
    Filters\expectAdded('woocommerce_admin_settings_sanitize_option_vcr_api_key')->once();
    \Brain\Monkey\Actions\expectAdded('woocommerce_update_options_vcr')->once();

    $keyStore = Mockery::mock(KeyStore::class);
    $catalog = Mockery::mock(CashierCatalog::class);

    new VcrSettingsTab($keyStore, $catalog, stubDepartmentCatalog());
});

it('exposes "vcr" as the WC settings page id', function (): void {
    $keyStore = Mockery::mock(KeyStore::class);
    $catalog = Mockery::mock(CashierCatalog::class);

    $tab = new VcrSettingsTab($keyStore, $catalog, stubDepartmentCatalog());

    expect($tab->id)->toBe('vcr');
});

/**
 * @param  array<int, array<string, mixed>> $settings
 * @return array<string, mixed>
 */
function fieldById(array $settings, string $id): array
{
    foreach ($settings as $field) {
        if (($field['id'] ?? null) === $id) {
            return $field;
        }
    }

    throw new RuntimeException("No settings field with id {$id}");
}

it('renders the department picker as a dropdown labelled with the tax regime', function (): void {
    // The regression this covers: the field used to be `type => number`,
    // so an admin picked a tax regime by typing an integer they had no
    // way to interpret. Department 1 is VAT on every register.
    // KeyStore::isSet() is derived from get(), so stubbing get() is what
    // actually drives the "credentials present" branch here.
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('get')->andReturn('api-key');

    $catalog = Mockery::mock(CashierCatalog::class);
    $catalog->allows('list')->andReturn([]);

    $departments = Mockery::mock(DepartmentCatalog::class);
    $departments->allows('list')->andReturn([
        1 => 'VAT — Bakery (#1)',
        4 => 'Micro-enterprise (#4)',
    ]);

    Functions\when('wp_kses_post')->returnArg(1);

    $tab = new VcrSettingsTab($keyStore, $catalog, $departments);
    $field = fieldById($tab->get_settings(), 'vcr_default_department_id');

    expect($field['type'])->toBe('select');
    expect($field['options'])->toHaveKey(1, 'VAT — Bakery (#1)');
    expect($field['options'])->toHaveKey(4, 'Micro-enterprise (#4)');
    // No preselected department — picking one is the admin's call, and a
    // default here would reintroduce exactly the silent-wrong-regime bug.
    expect($field['default'])->toBe('');
    expect($field['options'][''])->toContain('select a department');
    expect($field)->not->toHaveKey('custom_attributes');
});

it('disables the department picker when the API key is not saved yet', function (): void {
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('get')->andReturn(null);

    $catalog = Mockery::mock(CashierCatalog::class);
    $catalog->allows('list')->andReturn([]);

    $departments = Mockery::mock(DepartmentCatalog::class);
    $departments->allows('list')->andReturn([]);

    Functions\when('wp_kses_post')->returnArg(1);

    $tab = new VcrSettingsTab($keyStore, $catalog, $departments);
    $field = fieldById($tab->get_settings(), 'vcr_default_department_id');

    expect($field['custom_attributes'])->toBe(['disabled' => 'disabled']);
    expect($field['options'][''])->toContain('Save your API key first');
});
