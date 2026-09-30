import { test, expect } from '@playwright/test';
import { evalFile, readVcrMeta, resetFiscalMeta, wpCli } from './helpers/wp-cli.mjs';
import { getMockLog, resetMockLog, resetMockPlan, setMockPlan } from './helpers/mock-vcr.mjs';

/**
 * End-to-end happy path: create a paid order against the mock VCR
 * server, dispatch the Action Scheduler queue once, assert the order
 * lands in `_vcr_fiscal_status = success` with the SRC identifiers
 * the mock fed back.
 *
 * What this proves:
 *   - The *built artefact* loads in real WP/WC — the ZIP that gets
 *     published, with the Strauss-scoped vendor tree, not the working
 *     tree's unscoped one. That distinction is the whole of v0.1.0.
 *   - WC payment_complete hook is wired to FiscalQueue::enqueue
 *   - Action Scheduler accepts and runs the action
 *   - SaleRegistrarFactory builds a working VcrClient against an
 *     arbitrary base URL
 *   - FiscalStatusMeta persists the SRC response correctly
 *   - The full request payload conforms to the SDK's wire format
 *     (mock's JSON parsing wouldn't accept malformed)
 *
 * What it does NOT prove (covered elsewhere):
 *   - Retry/backoff timing — slow, lives in unit tests
 *   - Render of every meta-box state — unit-tested via output capture
 *   - SRC's actual schema acceptance — needs staging integration
 */
test.describe('VCR fiscal flow (happy path)', () => {
    test.beforeEach(async () => {
        await resetMockLog();
        await resetMockPlan();
        await setMockPlan('registerSale', {
            status: 200,
            body: {
                urlId: 'rcpt-e2e-1',
                saleId: 42,
                crn: 'CRN-E2E',
                srcReceiptId: 100,
                fiscal: 'FISCAL-E2E',
            },
        });

        // Sweep prior fiscal meta + pending AS actions so each test
        // starts from a clean slate. Without this, leftover queued
        // actions from a previous run all fire alongside the new one
        // and assertions about call counts become flaky.
        await resetFiscalMeta();

        await wpCli([
            'db', 'query',
            "DELETE FROM wp_actionscheduler_actions WHERE hook = 'vcr_fiscalize_order'",
        ]).catch(() => { /* fresh DB */ });
    });

    test('a paid WooCommerce order ends up registered with the mock SRC', async () => {
        // 1+2. Product + order in a single eval-file: gives us full
        // control over WC API calls, ensures the status-transition
        // hook fires (which `wc shop_order create --status=processing`
        // skips, since it sets the status without firing a transition).
        const orderId = await evalFile('create-paid-order.php');

        expect(orderId).toMatch(/^\d+$/);

        // 3. Run the Action Scheduler queue. The plugin's enqueue is
        // async, so we have to dispatch it explicitly.
        // --hooks pin: the `--group=vcr` filter requires the group to
        // exist already, which it doesn't on a fresh DB until our
        // first enqueue runs through; pinning by hook works either way.
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        // 4. Read back the fiscal meta through WooCommerce's order CRUD,
        // so the assertion holds on HPOS and on legacy post storage alike.
        const byKey = await readVcrMeta(orderId);

        expect(byKey._vcr_fiscal_status).toBe('success');
        expect(byKey._vcr_fiscal).toBe('FISCAL-E2E');
        expect(byKey._vcr_crn).toBe('CRN-E2E');
        expect(byKey._vcr_url_id).toBe('rcpt-e2e-1');
        expect(byKey._vcr_external_id).toBe(`order_${orderId}`);

        // 5. Verify the mock saw exactly one POST /api/v1/sales with
        // a structurally valid payload.
        const log = await getMockLog();
        const salesCalls = log.filter((entry) => entry.url === '/api/v1/sales' && entry.method === 'POST');

        expect(salesCalls).toHaveLength(1);

        const payload = salesCalls[0].body;
        expect(payload).toMatchObject({
            cashier: { id: 1 },
            buyer: { type: 'individual' },
        });
        expect(payload.items).toHaveLength(1);
        expect(payload.items[0]).toMatchObject({
            offer: { externalId: 'E2E-SKU-1' },
            quantity: '1',
        });
        // Absent, not null: with no override configured the line inherits
        // the department of the offer it references, and the API tells the
        // two apart — a null fails its schema.
        expect('department' in payload.items[0]).toBe(false);
        // Sale path uses server-side auto-settle: the plugin sends the
        // tender and the VCR derives the AMD total from the items. No
        // client-computed `amount` block on the wire. The fixture pays via
        // `bacs`, which CashPaymentResolver maps to nonCash.
        expect(payload.amount).toBeUndefined();
        expect(payload.autoSettle).toEqual({ tender: 'nonCash' });

        // The request names the SDK and then this plugin, with the platform it
        // is running on. Before the plugin sent its own token every caller
        // looked alike in vcr.am's log, so a support question about a
        // WooCommerce store could not be answered from it.
        expect(salesCalls[0].userAgent).toMatch(
            /^vcr-am-sdk-php\/[\d.]+ \(\+\S+\) vcr-am-woocommerce\/[\d.]+ \(WordPress\/[^;]+; WooCommerce\/[^;]+; PHP\/[^)]+\)$/,
        );
    });

    test('a product the register has never seen is filed as its receipt is filed', async () => {
        // The wall this feature removes: before it, this order was held for
        // manual review because nobody had onboarded the product in VCR.
        // Set through PHP, not `wp option update`: wp-env parses its arguments
        // as JSON-ish values, so a numeric-looking code loses a trailing zero
        // (`56.10` arrives as `56.1`) before wp-cli ever sees it. A merchant
        // saving the settings form stores the string, so the test has to too --
        // and a trailing zero is worth carrying here for exactly that reason.
        await wpCli(['eval', "update_option('vcr_catalog_classifier_code', '56.10');"]);
        await wpCli(['eval', "update_option('vcr_catalog_department_id', '1');"]);

        try {
            const sku = `E2E-NEW-${Date.now()}`;
            const [orderId, productId] = (await evalFile('create-order-with-sku.php', [sku])).split(' ');

            expect(orderId).toMatch(/^\d+$/);
            expect(productId).toMatch(/^\d+$/);

            await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);
            expect((await readVcrMeta(orderId))._vcr_fiscal_status).toBe('success');

            const described = (await getMockLog())
                .filter((entry) => entry.url === '/api/v1/sales' && entry.method === 'POST');
            expect(described).toHaveLength(1);

            // The whole offer, inline: the API creates it while filing the
            // receipt. The id is minted from the product id, not the SKU.
            expect(described[0].body.items[0].offer).toEqual({
                externalId: `wc-${productId}`,
                title: { type: 'universal', content: `E2E product ${sku}` },
                type: 'product',
                classifierCode: '56.10',
                defaultMeasureUnit: 'pc',
                defaultDepartment: { id: 1 },
            });

            // Second order, same product: now that the register holds the
            // offer, the plugin references it and never re-declares its code —
            // which is what leaves a merchant free to refine that code in VCR.
            await resetMockLog();
            const [secondOrderId] = (await evalFile('create-order-with-sku.php', [sku])).split(' ');
            await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);
            expect((await readVcrMeta(secondOrderId))._vcr_fiscal_status).toBe('success');

            const referenced = (await getMockLog())
                .filter((entry) => entry.url === '/api/v1/sales' && entry.method === 'POST');
            expect(referenced).toHaveLength(1);
            expect(referenced[0].body.items[0].offer).toEqual({ externalId: `wc-${productId}` });
        } finally {
            await wpCli(['eval', "delete_option('vcr_catalog_classifier_code');"]);
            await wpCli(['eval', "delete_option('vcr_catalog_department_id');"]);
        }
    });
});
