import { test, expect } from '@playwright/test';
import { evalFile, readVcrMeta, resetFiscalMeta, wpCli } from './helpers/wp-cli.mjs';
import { getMockLog, resetMockLog, resetMockPlan, setMockPlan } from './helpers/mock-vcr.mjs';

/**
 * E2E: a store that prices in something other than AMD.
 *
 * The two directions are handled differently on purpose:
 *
 *   - **Sale.** Lines go out in the store currency, each tagged `currency`,
 *     and the VCR converts. Nothing is computed here.
 *   - **Refund.** SRC wants an AMD figure, so the plugin has to name one. It
 *     asks the VCR for the rate — the same service that fiscalised the sale,
 *     so one implementation of Tax Code art. 16 governs both.
 *
 * This is the v0.1.2 fix, and until now it had been verified exactly once, by
 * hand. The path it replaced had never worked in any release: it called a CBA
 * SOAP method that does not exist, so every foreign-currency refund since the
 * feature shipped was held for manual registration — and its unit test passed
 * throughout, because the fixture was written from the same wrong assumption
 * as the code.
 */
const RATE = 363.38;
const SALE_ID = 12345;

const SALE_RESPONSE = {
    status: 200,
    body: {
        urlId: 'rcpt-usd-1',
        saleId: SALE_ID,
        crn: 'CRN-USD',
        srcReceiptId: 500,
        fiscal: 'FISCAL-USD',
    },
};

const REFUND_RESPONSE = {
    status: 200,
    body: {
        urlId: 'rfd-usd-1',
        saleRefundId: 888,
        crn: 'REF-CRN-USD',
        receiptId: 600,
        fiscal: 'REF-FISCAL-USD',
    },
};

test.describe('foreign-currency store', () => {
    test.beforeEach(async () => {
        await resetMockLog();
        await resetMockPlan();
        await setMockPlan('registerSale', SALE_RESPONSE);
        await setMockPlan('registerSaleRefund', REFUND_RESPONSE);
        await resetFiscalMeta();
        await wpCli([
            'db', 'query',
            "DELETE FROM wp_actionscheduler_actions WHERE hook IN ('vcr_fiscalize_order', 'vcr_register_refund')",
        ]).catch(() => { /* fresh DB */ });

        // The bootstrap mu-plugin reads this on every request and keeps
        // `woocommerce_currency` in step, so setting it here survives the page
        // loads that wp-cli triggers.
        await wpCli(['option', 'update', 'vcr_e2e_currency', 'USD']);
    });

    test.afterEach(async () => {
        await wpCli(['option', 'update', 'vcr_e2e_currency', 'AMD']);
    });

    test('a USD sale ships the store currency and lets the VCR convert', async () => {
        const orderId = await evalFile('create-paid-order.php');

        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        const byKey = await readVcrMeta(orderId);
        expect(byKey._vcr_fiscal_status).toBe('success');

        const log = await getMockLog();
        const sales = log.filter((e) => e.url === '/api/v1/sales' && e.method === 'POST');
        expect(sales).toHaveLength(1);

        // Every line tagged, prices untouched. The plugin computes no AMD
        // figure on the sale path at all.
        for (const item of sales[0].body.items) {
            expect(item.currency).toBe('USD');
        }
        expect(sales[0].body.amount).toBeUndefined();

        // And it did not ask for a rate: the sale has no use for one.
        expect(log.filter((e) => e.url.startsWith('/api/v1/exchange-rate'))).toHaveLength(0);
    });

    test('a USD refund asks the VCR for the rate and files the AMD figure', async () => {
        const orderId = await evalFile('create-paid-order.php');
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);
        expect((await readVcrMeta(orderId))._vcr_fiscal_status).toBe('success');

        const refundId = await evalFile('create-full-refund.php', [orderId.trim()]);
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_register_refund', '--force']);

        const refundMeta = await readVcrMeta(refundId);
        expect(refundMeta._vcr_refund_status).toBe('success');

        const log = await getMockLog();

        // It asked for the order's currency, not a hardcoded one.
        const rateCalls = log.filter((e) => e.url.startsWith('/api/v1/exchange-rate'));
        expect(rateCalls).toHaveLength(1);
        expect(rateCalls[0].url).toContain('currency=USD');

        const refunds = log.filter((e) => e.url === '/api/v1/sales/refund' && e.method === 'POST');
        expect(refunds).toHaveLength(1);

        const payload = refunds[0].body;
        expect(payload.saleId).toBe(SALE_ID);
        expect(payload.refundAmounts.cash).toBeUndefined();

        // The fixture product is 1000 USD at quantity one, so the filed figure
        // is the full order total converted at the mocked rate. Asserted as a
        // number against the arithmetic rather than a literal, so a change to
        // the fixture price cannot quietly make this pass for the wrong reason.
        const expectedAmd = 1000 * RATE;
        expect(Number(payload.refundAmounts.nonCash)).toBeCloseTo(expectedAmd, 2);
    });

    test('with no rate available the refund is held, never filed at the raw figure', async () => {
        const orderId = await evalFile('create-paid-order.php');
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        // The VCR cannot answer. This is the shape the pre-0.1.2 code was
        // permanently in, because it called a CBA method that does not exist.
        await setMockPlan('getExchangeRate', {
            status: 503,
            body: { error: 'rate service unavailable (mock)' },
        });

        const refundId = await evalFile('create-full-refund.php', [orderId.trim()]);
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_register_refund', '--force']);

        const refundMeta = await readVcrMeta(refundId);

        // Held for a human, not retried and not guessed at. Filing 1000 where
        // the receipt said 363,380 would be a reversal to unpick, so no figure
        // is better than the raw one.
        expect(refundMeta._vcr_refund_status).toBe('manual_required');

        const log = await getMockLog();
        expect(log.filter((e) => e.url === '/api/v1/sales/refund')).toHaveLength(0);
    });
});
