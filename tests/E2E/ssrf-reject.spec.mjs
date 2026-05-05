import { test, expect } from '@playwright/test';
import { wpCli } from './helpers/wp-cli.mjs';

/**
 * E2E: SSRF guard — settings save filter rejects unsafe URLs.
 *
 * Validates the production code path (not just the unit-tested
 * SafeUrlValidator) by invoking the actual WC settings sanitize
 * filter that runs on the WooCommerce → Settings → VCR save
 * action. An admin who pastes a malicious URL must not be able to
 * persist it to wp_options — otherwise the next fiscal job would
 * send the API key to that URL.
 *
 * Two layers asserted:
 *   1. SafeUrlValidator::reject() classifies the URL as unsafe
 *   2. The WC sanitize_option filter chain returns '' for unsafe
 *      input (so wp_options never sees the bad value)
 *
 * URL list spans the high-impact attack vectors:
 *   - AWS metadata IP (cloud key exfil)
 *   - Loopback (local Redis / DBs)
 *   - RFC1918 (internal corporate)
 *   - file:// (no transport guard)
 *   - localhost hostname (mDNS / local DNS)
 *
 * The matching positive path (a clean https://vcr.am URL is accepted)
 * is exercised by the existing fiscal-flow.spec.mjs test which
 * actually performs HTTP against the mock server.
 */
test.describe('SafeUrlValidator + settings-save SSRF guard', () => {
    const MALICIOUS_URLS = [
        'http://169.254.169.254/latest/meta-data/',  // AWS / GCP metadata
        'http://127.0.0.1:6379/',                    // loopback Redis
        'http://10.0.0.5/api',                        // RFC1918
        'http://192.168.1.1/api',                     // RFC1918
        'http://localhost:9000/',                     // localhost hostname
        'http://[::1]/api',                           // IPv6 loopback
        'file:///etc/passwd',                         // non-http scheme
        'ftp://example.com/api',                      // non-http scheme
    ];

    test('SafeUrlValidator rejects every classic SSRF vector', async () => {
        const stdout = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/test-ssrf-validator.php',
            ...MALICIOUS_URLS,
        ]);

        const results = JSON.parse(stdout);

        expect(results).toHaveLength(MALICIOUS_URLS.length);
        for (const result of results) {
            expect(
                result.rejected,
                `URL ${result.url} should be rejected but was allowed`,
            ).toBe(true);
            expect(result.reason).toBeTruthy();
        }
    });

    test('settings-save filter returns empty string for malicious base URL', async () => {
        // Pick the highest-impact URL (AWS metadata) for the save-path
        // assertion — the rest are covered by the validator-level test
        // above.
        const malicious = 'http://169.254.169.254/latest/meta-data/';

        const stdout = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/test-settings-save-ssrf.php',
            malicious,
        ]);
        const result = JSON.parse(stdout);

        // The contract: filter rejects (returns ''), no save lands.
        expect(result.blocked).toBe(true);
        expect(result.filtered).toBe('');
        expect(result.saved).not.toContain('169.254');
    });

    test('settings-save filter accepts a legitimate https vcr.am URL', async () => {
        // Positive control — the same filter chain MUST allow real URLs
        // through, otherwise we'd have a self-DoS.
        const safe = 'https://vcr.am/api/v1';

        const stdout = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/test-settings-save-ssrf.php',
            safe,
        ]);
        const result = JSON.parse(stdout);

        expect(result.blocked).toBe(false);
        expect(result.filtered).toBe(safe);
    });
});
