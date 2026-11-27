import { test, expect } from '@playwright/test';
import { logIn, SETTINGS_PATH } from './helpers/admin.mjs';
import { evalFile, wpCli } from './helpers/wp-cli.mjs';

/**
 * E2E: the plugin keeps unrelated requests working when other code hands its
 * hooks hostile values.
 *
 * This reproduces the hook resilience check in WooCommerce's QIT activation
 * test (https://qit.woo.com/docs/managed-tests/activation/), which fails a
 * plugin whose activation breaks requests that have nothing to do with it.
 * tests/E2E/mu-plugins/vcr-e2e-hostile-hooks.php forces the same values QIT
 * does. The usual cause of a failure is a hook callback with a strict
 * parameter type receiving null or a WP_Error from a neighbour; see
 * tests/E2E/scripts/hostile-hook-args.php for the same class of bug on our
 * own filters.
 *
 * The URLs are requested twice, as QIT does: with the plugin inactive, so a
 * request the bench itself cannot serve is not blamed on us, and then with it
 * active. A 5xx that appears only in the second round, or any new debug.log
 * line naming the plugin's directory, fails the test.
 */

const PLUGIN = 'vcr-am-fiscal-receipts';

const URLS = [
    '/',
    '/?s=receipt',
    '/wp-admin/',
    '/wp-admin/plugins.php',
    '/wp-admin/profile.php',
    '/wp-admin/admin-ajax.php?action=rest-nonce',
    '/wp-admin/admin.php?page=wc-settings',
    SETTINGS_PATH,
    '/wp-admin/admin.php?page=wc-orders',
    '/wp-admin/edit.php?post_type=shop_order',
    '/wp-admin/edit.php?post_type=product',
    '/wp-admin/admin.php?page=wc-status',
    '/wp-json/',
    '/wp-json/wp/v2/posts',
    '/wp-json/wc/store/v1/products',
    '/wp-json/wc/store/v1/cart',
    '/wp-json/wc/v3/system_status',
];

async function debugLogSize() {
    const { size } = JSON.parse(await evalFile('read-debug-log.php'));
    return size;
}

async function debugLogSince(offset) {
    const { lines } = JSON.parse(await evalFile('read-debug-log.php', [String(offset)]));
    return lines;
}

/** Status per URL. A thrown network error is recorded, not raised. */
async function requestAll(page) {
    const statuses = {};
    for (const url of URLS) {
        const response = await page.request.get(url, { maxRedirects: 5 });
        statuses[url] = response.status();
    }
    return statuses;
}

async function sendMail() {
    // wp-env has no MTA, so wp_mail() returning false is expected. What must
    // not happen is a fatal, which would make wp-cli exit non-zero.
    return wpCli(['eval', "var_export(wp_mail('qit-resilience@example.com', 'Resilience', 'Body'));"]);
}

test.describe('hook resilience (QIT activation check)', () => {
    test.beforeAll(async () => {
        await wpCli(['option', 'update', 'vcr_e2e_hostile_hooks', '1']);
    });

    test.afterAll(async () => {
        await wpCli(['option', 'delete', 'vcr_e2e_hostile_hooks']);
        await wpCli(['plugin', 'activate', PLUGIN]);
    });

    test('hostile hook values break nothing the plugin is active for', async ({ page }) => {
        await logIn(page);

        await wpCli(['plugin', 'deactivate', PLUGIN]);
        const baseline = await requestAll(page);
        await sendMail();

        await wpCli(['plugin', 'activate', PLUGIN]);
        const offset = await debugLogSize();
        const active = await requestAll(page);
        await sendMail();
        const newLines = await debugLogSince(offset);

        const regressions = URLS.filter((url) => active[url] >= 500 && baseline[url] < 500)
            .map((url) => `${url}: ${baseline[url]} -> ${active[url]}`);
        expect(regressions, JSON.stringify({ baseline, active }, null, 2)).toEqual([]);

        const attributed = newLines.filter((line) => line.includes(`/plugins/${PLUGIN}/`));
        expect(attributed).toEqual([]);
    });
});
