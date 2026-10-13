import { test, expect } from '@playwright/test';
import { wpCli } from './helpers/wp-cli.mjs';

/**
 * E2E: the store finds out that a newer plugin exists.
 *
 * The plugin is installed from a ZIP, not from WordPress.org, so until this
 * existed nothing ever told a merchant a release had happened. The store that
 * prompted the work was six versions behind and still pressing "Test
 * connection" every few days.
 *
 * What a unit test cannot reach is the part that matters here: whether
 * WordPress, given the `Update URI` header and the answer our filter returns,
 * actually renders the update row and serves the changelog modal. Both of
 * those are core's code reading core's transient, several hooks away from
 * anything this plugin calls.
 *
 * The feed is the mock server (tests/E2E/mu-plugins redirects the vcr.am URL
 * at the HTTP layer), answering with version 9.9.9 so the row appears whatever
 * the build under test is numbered.
 *
 * Not covered: pressing "Update now". That downloads and unpacks the package,
 * which here is a URL for a release that does not exist — and in the container
 * the plugin directory is a bind mount, so a real install would replace the
 * build being tested. Everything up to the button is exercised; the button is
 * WordPress's own installer.
 */

const PLUGIN_BASENAME = 'vcr-am-fiscal-receipts/vcr-am-fiscal-receipts.php';
const PLUGIN_SLUG = 'vcr-am-fiscal-receipts';

async function logIn(page) {
    await page.goto('/wp-login.php');

    // See pairing-connect.spec.mjs: WP focuses and selects #user_login from a
    // setTimeout, which eats a fill that lands first.
    await page
        .waitForFunction(() => document.activeElement?.id === 'user_login', null, { timeout: 5000 })
        .catch(() => {});

    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/);
}

test.describe('being told a newer plugin exists', () => {
    let context;
    let page;

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page = await context.newPage();
        await logIn(page);

        // Both caches have to go: ours, so the feed is asked again, and core's,
        // so it re-runs the check instead of serving the answer it already had.
        await wpCli(['transient', 'delete', 'vcr_latest_release']);
        await wpCli(['transient', 'delete', 'update_plugins', '--network']);
        await wpCli(['eval', 'wp_update_plugins();']);
    });

    test.afterAll(async () => {
        await context?.close();
        // Leave no update pending for the specs that run after this one.
        await wpCli(['transient', 'delete', 'vcr_latest_release']);
        await wpCli(['transient', 'delete', 'update_plugins', '--network']);
    });

    test('core files the release as an update for this plugin', async () => {
        const stored = await wpCli([
            'eval',
            `$u = get_site_transient('update_plugins'); echo wp_json_encode($u->response['${PLUGIN_BASENAME}'] ?? null);`,
        ]);
        const offer = JSON.parse(stored);

        expect(offer).not.toBeNull();
        expect(offer.new_version).toBe('9.9.9');
        expect(offer.package).toContain('github.com');
        // Core fills these two in itself from the header and the plugin file;
        // their presence is how we know it accepted the offer rather than
        // merely stored it.
        expect(offer.id).toBe('https://vcr.am/plugins/vcr-am-fiscal-receipts');
        expect(offer.plugin).toBe(PLUGIN_BASENAME);
    });

    test('the plugins screen says so on our row', async () => {
        await page.goto('/wp-admin/plugins.php');

        const notice = page.locator(`tr#${PLUGIN_SLUG}-update`);
        await expect(notice).toContainText('There is a new version');
        await expect(notice).toContainText('9.9.9');
        // Matched on the href rather than the link text: core writes the
        // plugin's whole name into both links for screen readers, so "update
        // now" is never a contiguous accessible name.
        await expect(notice.locator('a[href*="action=upgrade-plugin"]')).toBeVisible();
        await expect(notice.locator('a[href*="tab=plugin-information"]')).toBeVisible();
    });

    // The details link is rendered by core as soon as the offer carries a slug,
    // and it goes through `plugins_api`. Without that filter answering, this
    // screen is an error message.
    test('the details screen shows the changelog the feed sent', async () => {
        await page.goto(
            `/wp-admin/plugin-install.php?tab=plugin-information&plugin=${PLUGIN_SLUG}&section=changelog`,
        );

        await expect(page.locator('#plugin-information-content')).toContainText(
            'A line only the feed could have put on this screen.',
        );
        await expect(page.locator('#plugin-information-title')).toContainText('VCR');
        // The sidebar box, built from the same feed: this is what stops a host
        // on PHP 8.2 being offered a build it cannot run.
        await expect(page.locator('#plugin-information-content')).toContainText(
            'Requires PHP Version',
        );
        await expect(page.locator('#plugin-information-content')).toContainText('8.3');

        // The container runs a patch release of the branch the readme names.
        // Core compares the two with `<=`, so handing it the bare branch puts
        // "has not been tested with your current version of WordPress" above
        // the changelog of a release that was tested exactly there.
        await expect(page.locator('#plugin-information-content')).not.toContainText(
            'has not been tested',
        );
    });
});
