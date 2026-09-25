import { test, expect } from '@playwright/test';
import { evalFile, readVcrMeta, resetFiscalMeta, wpCli } from './helpers/wp-cli.mjs';
import { getMockLog, resetMockLog, resetMockPlan, setMockPlan } from './helpers/mock-vcr.mjs';

/**
 * E2E: the cash tender, and the setting that defers it.
 *
 * Everything else in this suite pays by `bacs`, which settles as `nonCash`.
 * Cash is a separate branch in two places, and both of them decide something
 * the tax authority sees:
 *
 *   - PaymentMapper picks the `autoSettle.tender` the VCR settles the whole
 *     receipt against. Reporting a cash sale as non-cash misstates the
 *     register's cash drawer.
 *   - OrderListener honours `vcr_cash_fiscalize_on`. On `completed` a cash
 *     order must NOT be fiscalised when it reaches Processing — for a COD
 *     shop that is the difference between one receipt and a receipt plus a
 *     reversal every time a delivery is refused.
 *
 * The fixture reaches Processing via `update_status()`, not
 * `payment_complete()`, because that is what WooCommerce's own COD gateway
 * does — and `woocommerce_payment_complete` is never deferred.
 */
const SALE_RESPONSE = {
    status: 200,
    body: {
        urlId: 'rcpt-cash-1',
        saleId: 77,
        crn: 'CRN-CASH',
        srcReceiptId: 300,
        fiscal: 'FISCAL-CASH',
    },
};

async function clearQueue() {
    await wpCli([
        'db', 'query',
        "DELETE FROM wp_actionscheduler_actions WHERE hook = 'vcr_fiscalize_order'",
    ]).catch(() => { /* fresh DB */ });
}

function salesCalls(log) {
    return log.filter((entry) => entry.url === '/api/v1/sales' && entry.method === 'POST');
}

test.describe('VCR cash tender', () => {
    test.beforeEach(async () => {
        await resetMockLog();
        await resetMockPlan();
        await setMockPlan('registerSale', SALE_RESPONSE);
        await resetFiscalMeta();
        await clearQueue();
    });

    test.afterEach(async () => {
        // Leave the option as the plugin's default, whatever this test set —
        // the specs share one WordPress install and run in file order.
        await wpCli(['option', 'update', 'vcr_cash_fiscalize_on', 'processing']);
    });

    test('a cash-on-delivery order settles against the cash tender', async () => {
        await wpCli(['option', 'update', 'vcr_cash_fiscalize_on', 'processing']);

        const orderId = await evalFile('create-paid-order.php', ['cod']);
        expect(orderId).toMatch(/^\d+$/);

        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        const byKey = await readVcrMeta(orderId);
        expect(byKey._vcr_fiscal_status).toBe('success');
        expect(byKey._vcr_fiscal).toBe('FISCAL-CASH');

        const calls = salesCalls(await getMockLog());
        expect(calls).toHaveLength(1);
        expect(calls[0].body.autoSettle).toEqual({ tender: 'cash' });
    });

    test('with fiscalisation deferred to Completed, Processing files nothing', async () => {
        await wpCli(['option', 'update', 'vcr_cash_fiscalize_on', 'completed']);

        const orderId = await evalFile('create-paid-order.php', ['cod']);

        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        // Nothing reached the VCR, and the order carries no fiscal status —
        // not a failed one, not a pending one. The receipt simply has not
        // been filed yet.
        expect(salesCalls(await getMockLog())).toHaveLength(0);

        const beforeCompletion = await readVcrMeta(orderId);
        expect(beforeCompletion._vcr_fiscal_status).toBeUndefined();

        // Marking the order Completed is what releases it.
        await evalFile('complete-order.php', [orderId.trim()]);
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        const afterCompletion = await readVcrMeta(orderId);
        expect(afterCompletion._vcr_fiscal_status).toBe('success');

        const calls = salesCalls(await getMockLog());
        expect(calls).toHaveLength(1);
        expect(calls[0].body.autoSettle).toEqual({ tender: 'cash' });
    });

    test('the deferral applies to cash only — a card order still files at Processing', async () => {
        await wpCli(['option', 'update', 'vcr_cash_fiscalize_on', 'completed']);

        const orderId = await evalFile('create-paid-order.php', ['bacs']);

        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        const byKey = await readVcrMeta(orderId);
        expect(byKey._vcr_fiscal_status).toBe('success');

        const calls = salesCalls(await getMockLog());
        expect(calls).toHaveLength(1);
        expect(calls[0].body.autoSettle).toEqual({ tender: 'nonCash' });
    });
});
