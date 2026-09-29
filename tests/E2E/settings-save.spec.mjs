import { test, expect } from '@playwright/test';
import { evalFile } from './helpers/wp-cli.mjs';

/**
 * E2E: saving the API key through WooCommerce's own settings save.
 *
 * The merchant-visible action behind this is the first one anybody
 * performs: paste the key into WooCommerce → Settings → VCR, press Save.
 * It is also where 0.1.5 fatalled on hosts without the native ext-sodium,
 * and a unit test cannot see that far — the failure lived in the seam
 * between WC's save path, our sanitize filter and WordPress's libsodium
 * polyfill.
 *
 * So this drives the real seam: `WC_Settings_Page::save()` reading $_POST,
 * `WC_Admin_Settings::save_fields()` resolving the field list through
 * `get_settings()`, our `sanitize_option_vcr_api_key` filter diverting the
 * value into KeyStore, and KeyStore encrypting it. Then it reads the key
 * back the way every consumer does, through Configuration.
 *
 * The host-without-ext-sodium half of that regression is pinned in
 * tests/Unit/Settings/KeyStoreTest.php: an extension cannot be unloaded
 * from a running PHP process, so it is simulated there rather than here.
 */
test.describe('API key settings save', () => {
    // What tests/E2E/mu-plugins/vcr-e2e-bootstrap.php seeds, and what the
    // rest of the suite authenticates to the mock VCR server with.
    const SEEDED_KEY = 'e2e-test-api-key';
    const NEW_KEY = 'vcr_live_settings-save-spec';

    test.afterAll(async () => {
        await evalFile('save-api-key.php', [SEEDED_KEY]);
    });

    test('stores the key encrypted and reads it back', async () => {
        const stdout = await evalFile('save-api-key.php', [NEW_KEY]);
        const result = JSON.parse(stdout);

        expect(result.error).toBeNull();
        expect(result.stored).toBe(true);
        expect(result.roundTrip).toBe(NEW_KEY);
    });

    test('never writes the key to wp_options in plaintext', async () => {
        // Two separate options are in play: `vcr_api_key` is the field WC
        // knows about, which our filter always blanks, and
        // `vcr_api_key_encrypted` holds the ciphertext. A regression in
        // either direction leaks a credential into a table that lands in
        // every database export and most migration plugins.
        const stdout = await evalFile('read-api-key-options.php');
        const options = JSON.parse(stdout);

        expect(options.plainOption).toBe('');
        expect(options.encryptedOption).not.toContain(NEW_KEY);
        expect(options.encryptedOption.length).toBeGreaterThan(0);
    });
});

/**
 * The settings screen is shared ground: every other plugin on the store
 * gets to filter the same values we do, and one of them returning null
 * from a `woocommerce_get_settings_pages` callback used to be enough to
 * fatal the page our own tab lives on. These run against real
 * WooCommerce because that is where the filter chain actually exists.
 */
test.describe('filter arguments other plugins can pass', () => {
    test('survives a null settings-pages array and still registers the tab', async () => {
        const result = JSON.parse(await evalFile('hostile-hook-args.php'));

        expect(result.settingsPages.isArray).toBe(true);
        expect(result.settingsPages.tabIds).toContain('vcr');
    });

    test('leaves the base URL alone when the field never reached the server', async () => {
        const result = JSON.parse(await evalFile('hostile-hook-args.php'));

        // null, not '': WC skips a null-valued option, which is what
        // keeps a staging endpoint from being reset by an unrelated save.
        expect(result.baseUrl).toBeNull();
        // The API key option is the decoy row — always blanked, because
        // the real credential lives encrypted under another key.
        expect(result.apiKey).toBe('');
    });
});

/**
 * The setup screen itself. 0.1.7 turned it from a flat form into a
 * checklist, after a merchant with a working plugin wrote in asking which
 * parameters to enter — the screen could not tell him that his register had
 * no cashiers, and said "check your API key permissions" instead.
 *
 * Driven through real WooCommerce because the section dispatch
 * (`get_settings_for_default_section` and friends) is WC's, and because the
 * checklist's first line is only true if the plugin really did reach
 * `GET /whoami` over the wire.
 */
test.describe('setup checklist', () => {
    test('reports the register the key belongs to, sandbox and all', async () => {
        const result = JSON.parse(await evalFile('render-settings-sections.php'));

        expect(result.error).toBeUndefined();
        // Straight out of the mock's /whoami answer — nothing in the plugin
        // knows these strings.
        expect(result.checklist).toContain('E2E Merchant LLC');
        expect(result.checklist).toContain('01234567');
        expect(result.checklist).toContain('register #90');
        expect(result.checklist).toContain('sandbox');
        // Seeded cashier 1 is selected, so the cashier line is satisfied and
        // the panel's worst level is the sandbox warning, not an error.
        expect(result.checklist).toContain('notice-warning');
        expect(result.checklist).not.toContain('notice-error');
        // The sentence this release exists to delete.
        expect(result.checklist).not.toContain('API key permissions');
    });

    test('keeps the base URL and the department override out of the first screen', async () => {
        const result = JSON.parse(await evalFile('render-settings-sections.php'));

        expect(result.sections).toEqual(['', 'advanced']);

        expect(result.generalIds).toContain('vcr_api_key');
        expect(result.generalIds).toContain('vcr_default_cashier_id');
        expect(result.generalIds).toContain('vcr_shipping_sku');
        expect(result.generalIds).not.toContain('vcr_base_url');
        expect(result.generalIds).not.toContain('vcr_default_department_id');
        // And the checkbox that wrote an option nothing read is gone from
        // both sections.
        expect(result.generalIds).not.toContain('vcr_test_mode');
        expect(result.advancedIds).not.toContain('vcr_test_mode');

        expect(result.advancedIds).toEqual(['vcr_base_url', 'vcr_default_department_id']);
    });
});
