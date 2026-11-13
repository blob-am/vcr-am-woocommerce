import { test, expect } from '@playwright/test';
import { logIn, SETTINGS_PATH } from './helpers/admin.mjs';
import { evalFile, wpCli } from './helpers/wp-cli.mjs';

/**
 * E2E: the Russian and Armenian translations reach a real WordPress.
 *
 * The plugin is distributed as a ZIP from its own update feed, not from
 * WordPress.org, so no language pack is ever downloaded for it: the only
 * translations a store gets are the files inside the ZIP. And the plugin
 * registers no text domain by hand — it relies on WordPress finding those
 * files through the Domain Path header. Both halves are invisible to a unit
 * test. A build that forgot to compile the catalogues, or a WordPress that
 * stopped looking in /languages, would leave every screen in English with
 * nothing failing anywhere.
 *
 * So this runs against the built artefact and asks WordPress itself.
 */
test.describe('translations shipped in the ZIP', () => {
    test.beforeAll(async () => {
        // A locale WordPress has no core files for cannot be switched to,
        // which is also the state of a store that never chose the language.
        await wpCli(['language', 'core', 'install', 'ru_RU', 'hy']);
    });

    test('Russian, including all three plural forms', async () => {
        const strings = JSON.parse(await evalFile('translated-strings.php', ['ru_RU']));

        expect(strings.switched).toBe(true);
        expect(strings.receiptLink).toBe('Посмотреть чек');
        expect(strings.cashiers).toEqual({
            1: 'Доступен 1 кассир.',
            3: 'Доступно 3 кассира.',
            5: 'Доступно 5 кассиров.',
        });
        // Read from the plugin header, which is what the Plugins screen shows.
        expect(strings.description).toContain('фискальные чеки');
    });

    test('Armenian', async () => {
        const strings = JSON.parse(await evalFile('translated-strings.php', ['hy']));

        expect(strings.switched).toBe(true);
        expect(strings.receiptLink).toBe('Դիտել կտրոնը');
        expect(strings.cashiers['3']).toBe('Հասանելի է 3 գանձապահ։');
        expect(strings.description).toContain('ֆիսկալ կտրոններ');
    });

    test('English stays English', async () => {
        const strings = JSON.parse(await evalFile('translated-strings.php', ['en_US']));

        expect(strings.receiptLink).toBe('View your receipt');
    });
});

/**
 * The same thing as a merchant meets it: a store whose language is Russian,
 * and the settings screen opened in a browser.
 */
test.describe('settings screen on a Russian store', () => {
    let context;
    let page;
    let noticesBefore;

    test.beforeAll(async ({ browser }) => {
        await wpCli(['language', 'core', 'install', 'ru_RU']);
        noticesBefore = Number(await evalFile('count-early-translation-notices.php'));
        await wpCli(['site', 'switch-language', 'ru_RU']);

        context = await browser.newContext();
        page = await context.newPage();
        await logIn(page);
    });

    test.afterAll(async () => {
        // The language first, and unconditionally: every other spec reads
        // English labels, so a store left in Russian fails the rest of the
        // suite for a reason that has nothing to do with them.
        await wpCli(['site', 'switch-language', 'en_US']);
        await context?.close();
    });

    test('is in Russian', async () => {
        await page.goto(SETTINGS_PATH);

        await expect(page.getByRole('heading', { name: 'VCR — фискальные чеки для Армении' })).toBeVisible();
        await expect(page.getByRole('link', { name: /Подключить к VCR\.AM/ })).toBeVisible();
        await expect(page.getByText('Комментарий к чеку')).toBeVisible();
    });

    test('translates nothing before WordPress knows the language', async () => {
        await page.goto(SETTINGS_PATH);
        await page.goto('/wp-admin/plugins.php');

        const noticesAfter = Number(await evalFile('count-early-translation-notices.php'));

        expect(noticesAfter).toBe(noticesBefore);
    });
});
