import { test, expect } from '@playwright/test';
import { wpCli, wpCliJson } from './helpers/wp-cli.mjs';
import { resetMockLog, setMockPlan } from './helpers/mock-vcr.mjs';

/**
 * E2E: GDPR personal-data exporter end-to-end.
 *
 * Creates a paid order with a known billing email, drives it through
 * the fiscal flow to Success, then invokes the WP exporter chain
 * (the same callback `wp_privacy_personal_data_exporters` would
 * dispatch) for that email. Asserts our exporter group + items
 * appear with the SRC identifiers we registered.
 *
 * Why this matters:
 *   - Validates registerExporter wiring at the WP filter level
 *   - Validates the exporter callback returns the SAR-required fields
 *     (Article 15 — right of access)
 *   - Catches regressions in fiscalRowsFor() coverage that pure unit
 *     tests can miss when the meta layer evolves
 */
test.describe('GDPR personal-data exporter', () => {
    const TEST_EMAIL = 'gdpr-test@example.com';

    test.beforeEach(async () => {
        await resetMockLog();
        await setMockPlan('registerSale', {
            status: 200,
            body: {
                urlId: 'rcpt-gdpr-1',
                saleId: 777,
                crn: 'CRN-GDPR',
                srcReceiptId: 999,
                fiscal: 'FISCAL-GDPR',
            },
        });

        await wpCli([
            'db', 'query',
            "DELETE FROM wp_postmeta WHERE meta_key LIKE '_vcr_%'",
        ]).catch(() => {});
        await wpCli([
            'db', 'query',
            "DELETE FROM wp_actionscheduler_actions WHERE hook = 'vcr_fiscalize_order'",
        ]).catch(() => {});
    });

    test('exporter returns VCR group with SRC identifiers for a Success order', async () => {
        // 1. Order with known billing email
        const orderId = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/create-order-with-email.php',
            TEST_EMAIL,
        ]);
        expect(orderId).toMatch(/^\d+$/);

        // 2. Drive to Success
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        // Sanity — fiscal flow must have succeeded, otherwise the
        // exporter would have nothing to surface.
        const meta = await wpCliJson(['post', 'meta', 'list', orderId]);
        const byKey = Object.fromEntries(
            meta.filter((row) => row.meta_key.startsWith('_vcr_'))
                .map((row) => [row.meta_key, row.meta_value]),
        );
        expect(byKey._vcr_fiscal_status).toBe('success');

        // 3. Invoke the exporter for this email
        const stdout = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/run-gdpr-exporter.php',
            TEST_EMAIL,
        ]);

        const result = JSON.parse(stdout);

        // 4. Contract assertions
        expect(result).toHaveProperty('data');
        expect(result).toHaveProperty('done');
        expect(result.done).toBe(true);

        expect(Array.isArray(result.data)).toBe(true);
        expect(result.data.length).toBeGreaterThan(0);

        // Find the order group in the export
        const orderGroup = result.data.find((g) => g.item_id === `order-${orderId}`);
        expect(orderGroup).toBeDefined();
        expect(orderGroup.group_id).toBe('vcr-fiscal-receipts');

        // Index data rows by name for easier assertions
        const rowsByName = Object.fromEntries(
            orderGroup.data.map((r) => [r.name, r.value]),
        );

        // GDPR Article 15 SAR completeness — these fields MUST appear
        expect(rowsByName).toHaveProperty('Fiscal status');
        expect(rowsByName['Fiscal status']).toBe('success');
        expect(rowsByName).toHaveProperty('SRC CRN');
        expect(rowsByName['SRC CRN']).toBe('CRN-GDPR');
        expect(rowsByName).toHaveProperty('SRC fiscal serial');
        expect(rowsByName['SRC fiscal serial']).toBe('FISCAL-GDPR');
        expect(rowsByName).toHaveProperty('SRC sale id');
        expect(rowsByName['SRC sale id']).toBe('777');
        expect(rowsByName).toHaveProperty('SRC receipt url id');
        expect(rowsByName['SRC receipt url id']).toBe('rcpt-gdpr-1');
        expect(rowsByName).toHaveProperty('Attempt count');
        expect(rowsByName['Attempt count']).toBe('1');
    });

    test('exporter returns empty data for an unknown email', async () => {
        const stdout = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/run-gdpr-exporter.php',
            'nobody@nowhere.example',
        ]);

        const result = JSON.parse(stdout);

        expect(result.data).toEqual([]);
        expect(result.done).toBe(true);
    });
});
