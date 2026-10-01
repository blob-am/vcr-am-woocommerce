import { test, expect } from '@playwright/test';
import { evalFile, resetFiscalMeta, wpCli } from './helpers/wp-cli.mjs';
import { getMockLog, resetMockLog, resetMockPlan, setMockPlan } from './helpers/mock-vcr.mjs';

/**
 * Editing "Name on the fiscal receipt" on a product already in the register's
 * catalogue renames the catalogue item, so the next receipt prints the new
 * name.
 *
 * What this proves that the unit tests cannot:
 *   - The whole chain is wired in real WP/WC, in the built artefact: the
 *     product-save hook reaches ReceiptNameField, the change fires
 *     `vcr_receipt_name_changed`, OfferTitleListener enqueues, Action
 *     Scheduler accepts and runs the action, and OfferTitleSync's two calls go
 *     out over HTTP in the right order with the right payloads.
 *   - The rename is a real `PATCH /api/v1/offers/{id}` carrying a universal
 *     title, which is what the API accepts.
 *   - It compares before writing: a save that does not change the name sends
 *     nothing at all.
 *
 * The admin screen itself is not driven -- the suite has no wp-admin session --
 * so the fixture fires `woocommerce_process_product_meta` with $_POST set, the
 * way WooCommerce does on Update.
 */
test.describe('Receipt name sync', () => {
    /** The offer id the mock hands out for the product under test. */
    const OFFER_ID = 4242;

    test.beforeEach(async () => {
        await resetMockLog();
        await resetMockPlan();
        await resetFiscalMeta();

        await wpCli([
            'db', 'query',
            "DELETE FROM wp_actionscheduler_actions WHERE hook IN ('vcr_fiscalize_order', 'vcr_sync_offer_title')",
        ]).catch(() => { /* fresh DB */ });

        // Products are reused by SKU across runs, and the plugin writes only
        // on an actual change -- so a previous run's receipt name would make
        // this one a no-op and the spec would pass or fail depending on what
        // ran before it. Each test states its own starting point instead.
        // Product meta lives in wp_postmeta whichever order datastore is in
        // play; HPOS moves orders, not products.
        await wpCli([
            'db', 'query',
            "DELETE FROM wp_postmeta WHERE meta_key IN ('_vcr_receipt_name', '_vcr_offer_external_id')",
        ]).catch(() => { /* fresh DB */ });
    });

    test('an edited receipt name reaches the catalog item the product was filed under', async () => {
        const [, productId] = (await evalFile('create-order-with-sku.php', ['E2E-RENAME-1'])).trim().split(' ');

        // Bind the product to a catalogue item the way a receipt does, without
        // filing one: the sync only ever runs for a product that has already
        // been fiscalised, and that binding is the meta it reads.
        await wpCli(['post', 'meta', 'update', productId, '_vcr_offer_external_id', `wc-${productId}`]);

        // The register holds that item, under the product's own name. One
        // `multi` row is what the API stores for a universal title.
        await setMockPlan('listOffers', {
            status: 200,
            body: [
                {
                    id: OFFER_ID,
                    externalId: `wc-${productId}`,
                    type: 'product',
                    classifierCode: '56.10',
                    defaultMeasureUnit: 'pc',
                    defaultDepartment: { internalId: 1 },
                    title: [{ id: 1, language: 'multi', content: 'E2E product E2E-RENAME-1' }],
                    archivedAt: null,
                    createdAt: '2026-09-01T00:00:00Z',
                },
            ],
        });

        await evalFile('set-receipt-name.php', [productId, 'Chemex 6 cup']);
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_sync_offer_title', '--force']);

        const log = await getMockLog();
        const renames = log.filter((entry) => entry.method === 'PATCH');

        expect(renames).toHaveLength(1);
        expect(renames[0].url).toBe(`/api/v1/offers/${OFFER_ID}`);
        expect(renames[0].body).toEqual({ title: { type: 'universal', content: 'Chemex 6 cup' } });

        // The read that decided it: filtered by external id, not a whole-catalogue walk.
        const reads = log.filter((entry) => entry.method === 'GET' && entry.url.startsWith('/api/v1/offers'));
        expect(reads.some((entry) => entry.url.includes(`externalId=wc-${productId}`))).toBe(true);
    });

    test('saving a product without changing the name sends nothing', async () => {
        const [, productId] = (await evalFile('create-order-with-sku.php', ['E2E-RENAME-2'])).trim().split(' ');

        await wpCli(['post', 'meta', 'update', productId, '_vcr_offer_external_id', `wc-${productId}`]);
        await wpCli(['post', 'meta', 'update', productId, '_vcr_receipt_name', 'Chemex 6 cup']);

        await evalFile('set-receipt-name.php', [productId, 'Chemex 6 cup']);
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_sync_offer_title', '--force']);

        const log = await getMockLog();

        expect(log.filter((entry) => entry.method === 'PATCH')).toHaveLength(0);
        // Not even the read: nothing was queued, so no action ran.
        expect(log.filter((entry) => entry.url.startsWith('/api/v1/offers'))).toHaveLength(0);
    });

    test('a title translated in VCR is left alone', async () => {
        const [, productId] = (await evalFile('create-order-with-sku.php', ['E2E-RENAME-3'])).trim().split(' ');

        await wpCli(['post', 'meta', 'update', productId, '_vcr_offer_external_id', `wc-${productId}`]);

        await setMockPlan('listOffers', {
            status: 200,
            body: [
                {
                    id: OFFER_ID,
                    externalId: `wc-${productId}`,
                    type: 'product',
                    classifierCode: '56.10',
                    defaultMeasureUnit: 'pc',
                    defaultDepartment: { internalId: 1 },
                    title: [
                        { id: 1, language: 'hy', content: 'Չեմեքս' },
                        { id: 2, language: 'ru', content: 'Кемекс' },
                        { id: 3, language: 'en', content: 'Chemex' },
                    ],
                    archivedAt: null,
                    createdAt: '2026-09-01T00:00:00Z',
                },
            ],
        });

        await evalFile('set-receipt-name.php', [productId, 'Chemex 6 cup']);
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_sync_offer_title', '--force']);

        const log = await getMockLog();

        expect(log.filter((entry) => entry.method === 'PATCH')).toHaveLength(0);
        // It did look, and decided not to write -- that is the difference from
        // the test above, where nothing was queued at all.
        expect(log.filter((entry) => entry.url.startsWith('/api/v1/offers'))).not.toHaveLength(0);
    });
});
