import { test, expect } from '@playwright/test';
import { wpCli, wpCliJson } from './helpers/wp-cli.mjs';
import { getMockLog, resetMockLog, setMockPlan } from './helpers/mock-vcr.mjs';

/**
 * End-to-end happy path: create a paid order against the mock VCR
 * server, dispatch the Action Scheduler queue once, assert the order
 * lands in `_vcr_fiscal_status = success` with the SRC identifiers
 * the mock fed back.
 *
 * What this proves:
 *   - Plugin loads in real WP/WC (vendor-prefixed autoloader works,
 *     no fatals on activation)
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
        await wpCli([
            'db', 'query',
            "DELETE FROM wp_postmeta WHERE meta_key LIKE '_vcr_%'",
        ]).catch(() => { /* fresh DB — nothing to delete */ });

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
        const orderId = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/create-paid-order.php',
        ]);

        expect(orderId).toMatch(/^\d+$/);

        // 3. Run the Action Scheduler queue. The plugin's enqueue is
        // async, so we have to dispatch it explicitly.
        // --hooks pin: the `--group=vcr` filter requires the group to
        // exist already, which it doesn't on a fresh DB until our
        // first enqueue runs through; pinning by hook works either way.
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        // 4. Read back the fiscal meta. WC stores meta as strings; we
        // assert the canonical Success state and the SRC identifiers.
        const meta = await wpCliJson([
            'post', 'meta', 'list', orderId,
        ]);

        const byKey = Object.fromEntries(
            meta.filter((row) => row.meta_key.startsWith('_vcr_'))
                .map((row) => [row.meta_key, row.meta_value]),
        );

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
            department: { id: 1 },
            quantity: '1',
        });
        // SaleAmount must be either cash or nonCash, sum > 0.
        expect(payload.amount.nonCash ?? payload.amount.cash).toBeDefined();
    });
});
