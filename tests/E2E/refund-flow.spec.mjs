import { test, expect } from '@playwright/test';
import { wpCli, wpCliJson } from './helpers/wp-cli.mjs';
import { getMockLog, resetMockLog, setMockPlan } from './helpers/mock-vcr.mjs';

/**
 * End-to-end happy path for the Phase 3e refund flow:
 *
 *   1. Create a paid order against the mock VCR server (re-uses the
 *      Phase 3b/4-lite fixture).
 *   2. Run AS once so the parent sale registers (status -> success).
 *   3. Create a FULL refund via wc_create_refund() — the same code path
 *      WC's admin "Refund" UI takes.
 *   4. Run AS again so the refund-registration job fires.
 *   5. Assert: refund meta lands in `_vcr_refund_status = success`
 *      with the SRC refund identifiers the mock fed back.
 *
 * What this proves:
 *   - `woocommerce_order_refunded` hook is wired to RefundQueue
 *   - RefundEligibilityChecker accepts a single full refund
 *   - SaleRefundRegistrarFactory builds a working SDK call to
 *     POST /api/v1/sales/refund
 *   - RefundStatusMeta persists the SRC refund response correctly
 *   - The payload conforms to RegisterSaleRefundInput (mock parses it)
 *   - The refund job uses the parent's `_vcr_sale_id` to address the
 *     refund — without that handoff the SDK call would fail
 *
 * Not covered here (lives elsewhere):
 *   - Partial refund routing → ManualRequired (unit-tested)
 *   - Cumulative refunds (second refund on same order) → ManualRequired
 *     (unit-tested via RefundEligibilityChecker)
 *   - Retry/backoff timing on transient failures (unit-tested)
 */
test.describe('VCR refund flow (happy path)', () => {
    test.beforeEach(async () => {
        await resetMockLog();

        // Reset both endpoint plans to the sane defaults.
        await setMockPlan('registerSale', {
            status: 200,
            body: {
                urlId: 'rcpt-e2e-1',
                saleId: 12345,
                crn: 'CRN-E2E',
                srcReceiptId: 100,
                fiscal: 'FISCAL-E2E',
            },
        });
        await setMockPlan('registerSaleRefund', {
            status: 200,
            body: {
                urlId: 'rfd-e2e-1',
                saleRefundId: 999,
                crn: 'REF-CRN-E2E',
                receiptId: 200,
                fiscal: 'REF-FISCAL-E2E',
            },
        });

        // Sweep prior fiscal/refund meta + pending AS actions so each
        // test starts clean.
        await wpCli([
            'db', 'query',
            "DELETE FROM wp_postmeta WHERE meta_key LIKE '_vcr_%'",
        ]).catch(() => { /* fresh DB */ });

        await wpCli([
            'db', 'query',
            "DELETE FROM wp_actionscheduler_actions WHERE hook IN ('vcr_fiscalize_order', 'vcr_register_refund')",
        ]).catch(() => { /* fresh DB */ });
    });

    test('a refund of a registered sale is registered with the mock SRC', async () => {
        // 1. Create the parent paid order. Re-uses Phase-4-lite fixture.
        const orderId = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/create-paid-order.php',
        ]);
        expect(orderId).toMatch(/^\d+$/);

        // 2. Run the sale fiscalisation AS hook so the parent registers.
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        // 3. Create a full refund via wc_create_refund() — same path WC
        // admin's "Refund" button takes. The order id is passed as a
        // positional arg; wp-cli surfaces it via the `$args` global
        // inside the eval-file script.
        const refundId = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/create-full-refund.php',
            orderId.trim(),
        ]);
        expect(refundId).toMatch(/^\d+$/);

        // 4. Run the refund-registration AS hook.
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_register_refund', '--force']);

        // 5. Read back refund meta and assert the success state.
        const refundMeta = await wpCliJson(['post', 'meta', 'list', refundId.trim()]);

        const byKey = Object.fromEntries(
            refundMeta.filter((row) => row.meta_key.startsWith('_vcr_'))
                .map((row) => [row.meta_key, row.meta_value]),
        );

        expect(byKey._vcr_refund_status).toBe('success');
        expect(byKey._vcr_refund_url_id).toBe('rfd-e2e-1');
        expect(byKey._vcr_refund_crn).toBe('REF-CRN-E2E');
        expect(byKey._vcr_refund_fiscal).toBe('REF-FISCAL-E2E');
        expect(byKey._vcr_sale_refund_id).toBe('999');
        expect(byKey._vcr_refund_external_id).toBe(`refund_${refundId.trim()}`);

        // 6. Verify the mock saw exactly one POST /api/v1/sales/refund
        // referencing the parent's saleId. This is the contract: refund
        // payloads must address the parent sale by its server-side id.
        const log = await getMockLog();
        const refundCalls = log.filter((entry) =>
            entry.url === '/api/v1/sales/refund' && entry.method === 'POST',
        );

        expect(refundCalls).toHaveLength(1);

        const payload = refundCalls[0].body;
        expect(payload).toMatchObject({
            cashier: { id: 1 },
            saleId: 12345, // matches what the mock returned at registerSale time
        });
        // items must be omitted (or null) — that's the SDK contract for
        // a full refund. RefundJob always passes items=null in v0.1.
        expect(payload.items).toBeUndefined();
        // refundAmounts must mirror the parent's payment mode (nonCash
        // for `bacs`, the test fixture's payment method).
        expect(payload.refundAmounts.nonCash).toBeDefined();
        expect(payload.refundAmounts.cash).toBeUndefined();
    });
});
