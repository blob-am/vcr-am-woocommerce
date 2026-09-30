import { test, expect } from '@playwright/test';
import { evalFile } from './helpers/wp-cli.mjs';
import { getMockLog, resetMockLog, resetMockPlan, setMockPlan } from './helpers/mock-vcr.mjs';

/**
 * E2E: the connect button, driven the way a merchant drives it.
 *
 * This is the one flow in the plugin that spans two sites and a browser
 * redirect, and almost nothing about it is visible to a unit test: the nonce
 * WordPress puts on the button, the admin-post round trip, the transient
 * surviving between two requests, vcr.am redirecting back with a code, and
 * WooCommerce's settings screen rendering the result. The unit tests cover
 * the decisions; this covers the seam they all sit in.
 *
 * It is the first browser-driven spec in the suite — the others drive wp-cli,
 * because they test things a browser cannot see. Here the browser is the
 * point: the handshake is carried by the merchant's session.
 *
 * `/__test/approve` on the mock stands in for the consent screen, which needs
 * a signed-in merchant this suite has no way to be. It approves
 * unconditionally and redirects to whatever `redirectUri` the plugin
 * registered — which is also what makes a wrong redirectUri fail here.
 */

// What tests/E2E/mu-plugins/vcr-e2e-bootstrap.php seeds, and what the rest of
// the suite authenticates to the mock with. Restored afterwards so a run of
// this spec does not change what every later spec sends.
const SEEDED_KEY = 'e2e-test-api-key';

// The mock's exchangePairingCode plan hands this back.
const PAIRED_KEY = 'paired-key-from-exchange';

const SETTINGS_PATH = '/wp-admin/admin.php?page=wc-settings&tab=vcr';

/**
 * Logs the admin in, once per file.
 *
 * The wait for focus is load-bearing rather than defensive. WordPress's login
 * page calls `wp_attempt_focus()` from a `setTimeout`, which focuses AND
 * selects `#user_login` a couple of hundred milliseconds after load — fill the
 * fields before that fires and the selection swallows the second value, so the
 * username ends up reading "password" and the password field empty. That is
 * exactly what the failure screenshot showed when this spec ran after the rest
 * of the suite, i.e. when the machine was loaded enough for the race to land
 * the other way.
 */
async function logIn(page) {
    await page.goto('/wp-login.php');

    await page
        .waitForFunction(() => document.activeElement?.id === 'user_login', null, { timeout: 5000 })
        .catch(() => {
            // Older or filtered login screens may never focus anything. The
            // fills below are still correct; only the race is unguarded.
        });

    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');

    // Cheap, and it fails here with a clear message instead of thirty seconds
    // later as "waiting for navigation".
    await expect(page.locator('#user_login')).toHaveValue('admin');

    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/);
}

test.describe('connecting a store to a register', () => {
    /** One signed-in browser context for the whole file. */
    let context;
    let page;

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page = await context.newPage();
        await logIn(page);
    });

    test.beforeEach(async () => {
        await resetMockPlan();
        await resetMockLog();
    });

    test.afterAll(async () => {
        await context.close();
        await evalFile('save-api-key.php', [SEEDED_KEY]);
    });

    test('takes a merchant from the settings screen to a stored key', async () => {
        await page.goto(SETTINGS_PATH);

        // The button has to be findable by its label: it is the only thing
        // telling a merchant this path exists.
        await page.getByRole('link', { name: /Connect to VCR\.AM|Reconnect to VCR\.AM/ }).click();

        // One click, then the whole round trip: admin-post -> registerRequest
        // -> approval -> redirect back -> exchange -> key stored.
        await page.waitForURL(/vcr_pairing=connected/);

        await expect(page.locator('.notice-success')).toContainText('Connected');

        // The secrets must not survive in the address bar, where a refresh
        // would replay a spent code and browser history would keep it.
        expect(page.url()).not.toContain('code=');
        expect(page.url()).not.toContain('state=');

        const stored = JSON.parse(await evalFile('read-api-key.php'));
        expect(stored.apiKey).toBe(PAIRED_KEY);
    });

    test('registers this store\'s own settings URL as the redirect', async () => {
        await page.goto(SETTINGS_PATH);
        await page.getByRole('link', { name: /Connect to VCR\.AM|Reconnect to VCR\.AM/ }).click();
        await page.waitForURL(/vcr_pairing=/);

        const log = await getMockLog();
        const registered = log.find((entry) => entry.url === '/api/v1/connect/requests');

        expect(registered).toBeDefined();
        // The merchant is shown this on the consent screen before approving,
        // so it has to be a page they would recognise as their own shop.
        expect(registered.body.redirectUri).toContain('page=wc-settings');
        expect(registered.body.redirectUri).toContain('tab=vcr');
        expect(registered.body.storeName).toBeTruthy();
    });

    test('sends a challenge and never the verifier', async () => {
        // If the verifier ever travelled with the request, PKCE would be
        // decorative: whoever intercepted the redirect could exchange the code.
        await page.goto(SETTINGS_PATH);
        await page.getByRole('link', { name: /Connect to VCR\.AM|Reconnect to VCR\.AM/ }).click();
        await page.waitForURL(/vcr_pairing=/);

        const log = await getMockLog();
        const registered = log.find((entry) => entry.url === '/api/v1/connect/requests');
        const exchanged = log.find((entry) => entry.url === '/api/v1/connect/exchange');

        expect(registered.body.codeChallenge).toHaveLength(43);
        expect(registered.body.codeVerifier).toBeUndefined();

        // The verifier appears exactly once, at the exchange, from the server.
        expect(exchanged.body.codeVerifier).toBeTruthy();
        expect(exchanged.body.codeVerifier).not.toBe(registered.body.codeChallenge);
    });

    test('announces itself so vcr.am can name the build in its request log', async () => {
        await page.goto(SETTINGS_PATH);
        await page.getByRole('link', { name: /Connect to VCR\.AM|Reconnect to VCR\.AM/ }).click();
        await page.waitForURL(/vcr_pairing=/);

        const log = await getMockLog();
        const registered = log.find((entry) => entry.url === '/api/v1/connect/requests');

        expect(registered.userAgent).toContain('vcr-am-sdk-php/');
        expect(registered.userAgent).toContain('vcr-am-woocommerce/');
    });

    test('leaves the existing key alone when the exchange fails', async () => {
        // The merchant has a working key. A failed reconnect that wiped it
        // would stop a live store filing receipts.
        await evalFile('save-api-key.php', [SEEDED_KEY]);

        await setMockPlan('exchangePairingCode', {
            status: 400,
            body: { error: 'That code cannot be exchanged.' },
        });

        await page.goto(SETTINGS_PATH);
        await page.getByRole('link', { name: /Connect to VCR\.AM|Reconnect to VCR\.AM/ }).click();
        await page.waitForURL(/vcr_pairing=failed/);

        await expect(page.locator('.notice-error')).toBeVisible();

        const stored = JSON.parse(await evalFile('read-api-key.php'));
        expect(stored.apiKey).toBe(SEEDED_KEY);
    });

    test('refuses a code that arrives without a pending attempt', async () => {
        // What a replayed or forged redirect looks like: a code, a state, and
        // nothing on this server that remembers starting it.
        await evalFile('save-api-key.php', [SEEDED_KEY]);

        await page.goto(`${SETTINGS_PATH}&code=made-up&state=made-up`);

        await page.waitForURL(/vcr_pairing=expired/);

        const stored = JSON.parse(await evalFile('read-api-key.php'));
        expect(stored.apiKey).toBe(SEEDED_KEY);
    });
});
