<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\ReadinessPanel;
use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\CatalogListing;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionState;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\RegisterIdentity;
use BlobSolutions\WooCommerceVcrAm\Settings\AdvancedFields;
use BlobSolutions\WooCommerceVcrAm\Settings\GeneralFields;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Settings\VcrSettingsTab;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('wp_salt')->justReturn(str_repeat('x', 64));
    Functions\when('wp_kses_post')->returnArg(1);

    // The section description now also carries the connect button, which
    // builds a nonced admin-post URL. These are WordPress's, not ours; the
    // button's own wording and target are covered in ConnectButtonTest.
    Functions\when('admin_url')->alias(
        static fn (string $path = ''): string => 'https://shop.example/wp-admin/' . $path,
    );
    Functions\when('wp_nonce_url')->alias(
        static fn (string $url, string $action): string => $url . '&_wpnonce=test-nonce&a=' . $action,
    );
    Functions\when('esc_url')->returnArg(1);
    Functions\when('esc_html')->returnArg(1);
});

/**
 * The tab is the WooCommerce adapter: the tab id, the two sections, the
 * save-path filters and cache invalidation. Those are what this file
 * covers, plus which section each field lands in — the thing a merchant
 * sees first and the thing a careless edit moves.
 *
 * The checklist's own wording lives in ReadinessPanelTest; the field
 * *rendering* (WC form markup) stays with the E2E suite, which drives real
 * WooCommerce.
 */
function stubTabKeyStore(bool $isSet = false): KeyStore
{
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('isSet')->andReturn($isSet);
    $keyStore->allows('get')->andReturn($isSet ? 'api-key' : null);

    return $keyStore;
}

/**
 * @param array<int, string> $entries
 */
function stubTabCashiers(array $entries = []): CashierCatalog
{
    $catalog = Mockery::mock(CashierCatalog::class);
    $catalog->allows('list')->andReturn(CatalogListing::of($entries));
    $catalog->allows('refresh');

    return $catalog;
}

/**
 * @param array<int, string> $entries
 */
function stubTabDepartments(array $entries = []): DepartmentCatalog
{
    $catalog = Mockery::mock(DepartmentCatalog::class);
    $catalog->allows('list')->andReturn(CatalogListing::of($entries));
    $catalog->allows('refresh');

    return $catalog;
}

function stubTabProbe(?ConnectionState $state = null): ConnectionProbe
{
    $probe = Mockery::mock(ConnectionProbe::class);
    $probe->allows('state')->andReturn($state ?? ConnectionState::noApiKey());
    $probe->allows('refresh');

    return $probe;
}

function stubTabConfig(?int $cashierId = null, ?int $departmentId = null): Configuration
{
    $config = Mockery::mock(Configuration::class);
    $config->allows('defaultCashierId')->andReturn($cashierId);
    $config->allows('defaultDepartmentId')->andReturn($departmentId);
    $config->allows('shippingSku')->andReturn('shipping');

    return $config;
}

function makeSettingsTab(
    ?KeyStore $keyStore = null,
    ?CashierCatalog $cashiers = null,
    ?DepartmentCatalog $departments = null,
    ?ConnectionProbe $probe = null,
    ?Configuration $config = null,
): VcrSettingsTab {
    $keyStore ??= stubTabKeyStore();
    $cashiers ??= stubTabCashiers();
    $departments ??= stubTabDepartments();
    $probe ??= stubTabProbe();
    $config ??= stubTabConfig();

    return new VcrSettingsTab(
        $keyStore,
        $cashiers,
        $departments,
        $probe,
        new GeneralFields(
            $keyStore,
            $cashiers,
            new ReadinessPanel($probe, $cashiers, $departments, $config),
        ),
        new AdvancedFields($departments),
    );
}

/**
 * @param  array<int, mixed> $settings
 * @return array<string, mixed>
 */
function fieldById(array $settings, string $id): array
{
    foreach ($settings as $field) {
        if (is_array($field) && ($field['id'] ?? null) === $id) {
            /** @var array<string, mixed> $field */
            return $field;
        }
    }

    throw new RuntimeException("No settings field with id {$id}");
}

/**
 * @param array<int, mixed> $settings
 */
function hasFieldId(array $settings, string $id): bool
{
    foreach ($settings as $field) {
        if (is_array($field) && ($field['id'] ?? null) === $id) {
            return true;
        }
    }

    return false;
}

it('exposes "vcr" as the WC settings page id', function (): void {
    expect(makeSettingsTab()->id)->toBe('vcr');
});

it('offers a General and an Advanced section', function (): void {
    $sections = makeSettingsTab()->get_sections();

    expect($sections)->toHaveKey('')
        ->and($sections)->toHaveKey(VcrSettingsTab::SECTION_ADVANCED);
});

it('keeps the two rarely-wanted fields out of the general section', function (): void {
    // Both have "leave empty" as the right answer, and the department
    // override rewrites the tax regime on every line of every receipt. On
    // the main screen they read as things to fill in.
    $general = makeSettingsTab()->get_settings();

    expect(hasFieldId($general, 'vcr_api_key'))->toBeTrue()
        ->and(hasFieldId($general, Configuration::OPT_DEFAULT_CASHIER_ID))->toBeTrue()
        ->and(hasFieldId($general, Configuration::OPT_SHIPPING_SKU))->toBeTrue()
        ->and(hasFieldId($general, Configuration::OPT_BASE_URL))->toBeFalse()
        ->and(hasFieldId($general, Configuration::OPT_DEFAULT_DEPARTMENT_ID))->toBeFalse();

    $advanced = makeSettingsTab()->get_settings(VcrSettingsTab::SECTION_ADVANCED);

    expect(hasFieldId($advanced, Configuration::OPT_BASE_URL))->toBeTrue()
        ->and(hasFieldId($advanced, Configuration::OPT_DEFAULT_DEPARTMENT_ID))->toBeTrue();
});

it('has no "test mode" field any more', function (): void {
    // It wrote an option nothing read, so a merchant could tick it, save,
    // and believe their live receipts had become test ones. Whether a
    // register is a sandbox is the register's own answer, and the checklist
    // reports it.
    $tab = makeSettingsTab();

    expect(hasFieldId($tab->get_settings(), 'vcr_test_mode'))->toBeFalse()
        ->and(hasFieldId($tab->get_settings(VcrSettingsTab::SECTION_ADVANCED), 'vcr_test_mode'))->toBeFalse();
});

it('serves the general section when WooCommerce passes a null current section', function (): void {
    // The save path hands us the `$current_section` global, which is null
    // rather than '' when a save is driven from code (WP-CLI, our own E2E
    // fixtures). The parent's dispatch turns null into a lookup for
    // `get_settings_for__section`, finds nothing, and would save nothing at
    // all — so the null has to be normalised before it gets there.
    $settings = makeSettingsTab()->get_settings(null);

    expect(hasFieldId($settings, 'vcr_api_key'))->toBeTrue();
});

it('settings tab title section description discloses the third-country data transfer', function (): void {
    $settings = makeSettingsTab()->get_settings();

    // The title field at the top of the tab must carry a description
    // that names Armenia, the SCC mechanism, and what data is sent.
    // This is the merchant's primary GDPR notice surface — they have
    // to see it before they paste credentials.
    expect($settings[0])->toBeArray();
    expect($settings[0]['type'])->toBe('title');
    expect($settings[0]['desc'])
        ->toContain('Armenia')
        ->toContain('Standard Contractual Clauses')
        ->toContain('NOT transmitted')
        ->toContain('vcr.am/privacy');
});

it('puts the readiness checklist above the disclosure, in the same description', function (): void {
    $identity = new RegisterIdentity(
        vcrId: 90,
        crn: '99123456',
        isSandbox: true,
        tradingName: 'Kravec Sandbox',
        entityName: 'Kravec LLC',
        tin: '01234567',
    );

    $settings = makeSettingsTab(
        probe: stubTabProbe(ConnectionState::connected($identity)),
        cashiers: stubTabCashiers([1 => '#1 (desk abc)']),
        config: stubTabConfig(cashierId: 1),
    )->get_settings();

    $desc = $settings[0]['desc'];
    expect($desc)->toBeString();

    $checklistAt = strpos($desc, 'notice');
    $disclosureAt = strpos($desc, 'Standard Contractual Clauses');

    expect($checklistAt)->not->toBeFalse()
        ->and($disclosureAt)->not->toBeFalse()
        ->and($checklistAt)->toBeLessThan($disclosureAt)
        ->and($desc)->toContain('register #90');
});

it('interceptApiKeySave trims and persists the key into KeyStore', function (): void {
    $stored = null;
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('isSet')->andReturn(false);
    $keyStore->expects('put')->andReturnUsing(function (string $value) use (&$stored): void {
        $stored = $value;
    });

    $tab = makeSettingsTab(keyStore: $keyStore);

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
    $keyStore->allows('isSet')->andReturn(true);
    $keyStore->expects('put')->never();

    expect(makeSettingsTab(keyStore: $keyStore)->interceptApiKeySave('', [], ''))->toBe('');
});

it('interceptApiKeySave skips KeyStore when the submitted value is pure whitespace', function (): void {
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('isSet')->andReturn(true);
    $keyStore->expects('put')->never();

    expect(makeSettingsTab(keyStore: $keyStore)->interceptApiKeySave("   \t\n", [], "   \t\n"))->toBe('');
});

it('interceptApiKeySave returns "" for non-string input without touching KeyStore', function (): void {
    // Defensive: WC's settings filter signature is loosely typed.
    // Anything that isn't a string is treated as "no submission".
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('isSet')->andReturn(true);
    $keyStore->expects('put')->never();

    $tab = makeSettingsTab(keyStore: $keyStore);

    expect($tab->interceptApiKeySave(null, [], ''))->toBe('')
        ->and($tab->interceptApiKeySave(false, [], ''))->toBe('')
        ->and($tab->interceptApiKeySave(['array'], [], ''))->toBe('');
});

it('interceptApiKeySave survives the null raw value WC passes for an absent field', function (): void {
    // WC computes the third filter argument as
    // `isset($data[$id]) ? wp_unslash($data[$id]) : null` and passes it
    // whether or not the field was submitted. A `string` type on that
    // parameter would make an absent field an uncaught TypeError — and
    // nothing in WC's save path catches, so the merchant gets a
    // WordPress critical error instead of saved settings.
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('isSet')->andReturn(true);
    $keyStore->expects('put')->never();

    expect(makeSettingsTab(keyStore: $keyStore)->interceptApiKeySave(null, [], null))->toBe('');
});

it('sanitizeBaseUrlSave returns null so WC leaves an unsubmitted field alone', function (): void {
    // WC skips an option whose filtered value is null, which is how a
    // field that never reached the server keeps its stored value. Return
    // '' here instead and a staging or self-hosted store is silently
    // moved back to the production endpoint by an unrelated save — and
    // since 0.1.7 the field lives in another section, so it is absent from
    // every save of the general one.
    expect(makeSettingsTab()->sanitizeBaseUrlSave(null, [], null))->toBeNull();
});

it('invalidateCaches refreshes both catalogs and the connection probe', function (): void {
    // All three are keyed on the same credentials, so a credentials change
    // has to drop all three. Leave the probe out and the checklist keeps
    // reporting the previous register — on the very screen that changed it.
    $cashiers = Mockery::mock(CashierCatalog::class);
    $cashiers->allows('list')->andReturn(CatalogListing::of([]));
    $cashiers->expects('refresh')->once();

    $departments = Mockery::mock(DepartmentCatalog::class);
    $departments->allows('list')->andReturn(CatalogListing::of([]));
    $departments->expects('refresh')->once();

    $probe = Mockery::mock(ConnectionProbe::class);
    $probe->allows('state')->andReturn(ConnectionState::noApiKey());
    $probe->expects('refresh')->once();

    makeSettingsTab(cashiers: $cashiers, departments: $departments, probe: $probe)->invalidateCaches();
});

it('registers the API key sanitize-option filter and update-options action on construction', function (): void {
    Filters\expectAdded('woocommerce_admin_settings_sanitize_option_vcr_api_key')->once();
    \Brain\Monkey\Actions\expectAdded('woocommerce_update_options_vcr')->once();

    makeSettingsTab();
});

it('renders the department picker as a dropdown labelled with the tax regime', function (): void {
    // The regression this covers: the field used to be `type => number`,
    // so an admin picked a tax regime by typing an integer they had no
    // way to interpret. Department 1 is VAT on every register.
    $departments = stubTabDepartments([
        1 => 'VAT — Bakery (#1)',
        4 => 'Micro-enterprise (#4)',
    ]);

    $tab = makeSettingsTab(keyStore: stubTabKeyStore(isSet: true), departments: $departments);
    $field = fieldById(
        $tab->get_settings(VcrSettingsTab::SECTION_ADVANCED),
        Configuration::OPT_DEFAULT_DEPARTMENT_ID,
    );

    expect($field['type'])->toBe('select');
    expect($field['options'])->toHaveKey(1, 'VAT — Bakery (#1)');
    expect($field['options'])->toHaveKey(4, 'Micro-enterprise (#4)');
    // Empty is the intended resting state, not an unfinished one: with no
    // override each line inherits its offer's own department. Preselecting
    // anything here would reintroduce the silent-wrong-regime bug, and the
    // placeholder has to read as a real choice rather than a prompt.
    expect($field['default'])->toBe('');
    expect($field['options'][''])->toContain("use each offer's own department");
    expect($field)->not->toHaveKey('custom_attributes');
});

it('points an empty dropdown at the checklist instead of guessing why it is empty', function (): void {
    // 0.1.6 said "check your API key permissions" here, for every cause —
    // including a perfectly good key whose register simply had nothing on
    // it. One place diagnoses now, and the dropdown defers to it.
    $tab = makeSettingsTab();
    $field = fieldById(
        $tab->get_settings(VcrSettingsTab::SECTION_ADVANCED),
        Configuration::OPT_DEFAULT_DEPARTMENT_ID,
    );

    expect($field['custom_attributes'])->toBe(['disabled' => 'disabled'])
        ->and($field['options'][''])->toContain('see the checklist above')
        ->and($field['options'][''])->not->toContain('API key permissions');
});
