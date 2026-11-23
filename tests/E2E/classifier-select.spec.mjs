import { test, expect } from '@playwright/test';
import { logIn, SETTINGS_PATH } from './helpers/admin.mjs';
import { evalFile, wpCli } from './helpers/wp-cli.mjs';

/**
 * E2E: the store-wide classifier code can only be one the tax service accepts.
 *
 * Until 0.1.14 this was a text box. 0630 CLUB typed "47", the checklist said
 * ready, and their first order failed with a bare "Validation failed". Now the
 * field is a search fed by vcr.am, the save path checks the code again, and
 * the checklist re-checks whatever an older version stored.
 *
 * The mock's /searchByClassifier answers over a five-code dictionary; see
 * CLASSIFIER_DICTIONARY in mock-vcr-server.mjs.
 */
const OPTION = 'vcr_catalog_classifier_code';

async function storedCode() {
    return (await wpCli(['option', 'get', OPTION])).trim();
}

test.describe('classifier code for new catalog items', () => {
    test.afterEach(async () => {
        await wpCli(['option', 'delete', OPTION]);
    });

    test('stores a code the tax service accepts', async () => {
        const result = JSON.parse(await evalFile('save-classifier-code.php', ['6109']));

        expect(result.error).toBeNull();
        expect(result.stored).toBe('6109');
    });

    test('refuses "47" and keeps the code it had', async () => {
        await wpCli(['option', 'update', OPTION, '6109']);

        const result = JSON.parse(await evalFile('save-classifier-code.php', ['47']));

        expect(result.error).toBeNull();
        expect(result.stored).toBe('6109');
    });

    test('clears the code when the merchant empties the field', async () => {
        await wpCli(['option', 'update', OPTION, '6109']);

        const result = JSON.parse(await evalFile('save-classifier-code.php', ['']));

        expect(result.stored).toBe('');
    });

    test.describe('in the browser', () => {
        let context;
        let page;

        test.beforeAll(async ({ browser }) => {
            context = await browser.newContext();
            page = await context.newPage();
            await logIn(page);
        });

        test.afterAll(async () => {
            await context.close();
        });

        test('flags a code an older version stored, and offers the real one', async () => {
            await wpCli(['option', 'update', OPTION, '47']);

            await page.goto(SETTINGS_PATH);

            const checklist = page.locator('.notice.inline').first();
            await expect(checklist).toHaveClass(/notice-error/);
            await expect(checklist).toContainText('Classifier code 47 is not one the tax service accepts');
            await expect(checklist).toContainText('47.00 — Retail trade services');
        });

        test('picks a code from the search and saves it', async () => {
            // The mock register has two departments; with none chosen the
            // checklist asks about that instead of naming the code.
            await wpCli(['option', 'update', 'vcr_catalog_department_id', '1']);
            await page.goto(SETTINGS_PATH);

            // selectWoo hides the native select and renders its own box next to it.
            const field = page.locator(`#${OPTION}`);
            await expect(field).toHaveClass(/vcr-classifier-select/);
            await field.locator('xpath=following-sibling::span[contains(@class, "select2")]').first().click();

            await page.locator('.select2-search__field').fill('t-shirt');
            const option = page.locator('.select2-results__option', { hasText: '6109 — T-shirts' });
            await option.click();

            await page.click('button[name="save"]');
            await page.waitForLoadState('load');

            expect(await storedCode()).toBe('6109');
            await expect(page.locator('.notice.inline').first()).toContainText('6109 — T-shirts');

            await wpCli(['option', 'delete', 'vcr_catalog_department_id']);
        });
    });
});
