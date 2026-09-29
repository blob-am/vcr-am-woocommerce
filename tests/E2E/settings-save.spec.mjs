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
