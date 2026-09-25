import { test, expect } from '@playwright/test';
import { evalFile, readVcrMeta, resetFiscalMeta, wpCli } from './helpers/wp-cli.mjs';
import { getMockLog, resetMockLog, resetMockPlan, setMockPlan } from './helpers/mock-vcr.mjs';

/**
 * E2E: the receipt must account for exactly the money WooCommerce charged.
 *
 * A fiscal receipt is built from item lines, but WooCommerce settles on the
 * order total, and the two can disagree. v0.1.1 shipped because they did: a
 * negative fee line — how gift-card, loyalty and store-credit extensions take
 * money off a total — was dropped from the receipt, so a 600 AMD order was
 * reported to the tax authority as 1000 AMD. Over-reporting revenue is not a
 * rounding bug; it is a wrong filing that can only be undone by a reversal.
 *
 * `ItemBuilder::assertItemsAccountForOrderTotal()` now refuses to build such a
 * receipt at all, and the order goes to ManualRequired for a human. That guard
 * is the whole of the fix and nothing exercised it end to end until now.
 *
 * The orders here are also the only ones in the suite with more than one item,
 * a quantity above one, shipping, or a fee.
 */
const SALE_RESPONSE = {
    status: 200,
    body: {
        urlId: 'rcpt-recon-1',
        saleId: 91,
        crn: 'CRN-RECON',
        srcReceiptId: 400,
        fiscal: 'FISCAL-RECON',
    },
};

function salesCalls(log) {
    return log.filter((entry) => entry.url === '/api/v1/sales' && entry.method === 'POST');
}

test.describe('receipt/charge reconciliation', () => {
    test.beforeEach(async () => {
        await resetMockLog();
        await resetMockPlan();
        await setMockPlan('registerSale', SALE_RESPONSE);
        await resetFiscalMeta();
        await wpCli([
            'db', 'query',
            "DELETE FROM wp_actionscheduler_actions WHERE hook = 'vcr_fiscalize_order'",
        ]).catch(() => { /* fresh DB */ });
    });

    test('a multi-item order with shipping and a fee adds up to what was charged', async () => {
        const order = JSON.parse(await evalFile('create-shaped-order.php', ['rich']));

        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        const byKey = await readVcrMeta(order.id);
        expect(byKey._vcr_fiscal_status).toBe('success');

        const calls = salesCalls(await getMockLog());
        expect(calls).toHaveLength(1);

        const { items } = calls[0].body;

        // Two products, shipping and the fee each get their own line.
        expect(items).toHaveLength(4);

        // The assertion that matters: what the receipt says was sold has to
        // equal what the customer was charged. Compared against WooCommerce's
        // own total rather than a hardcoded number, so the test does not
        // depend on how WooCommerce distributes a discount internally.
        const lineSum = items.reduce(
            (sum, item) => sum + Number(item.price) * Number(item.quantity),
            0,
        );

        expect(lineSum).toBeCloseTo(Number(order.total), 2);
    });

    test('a negative fee stops the receipt instead of understating it', async () => {
        const order = JSON.parse(await evalFile('create-shaped-order.php', ['negative-fee']));

        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        const byKey = await readVcrMeta(order.id);

        // ManualRequired, not Failed: nothing here is worth retrying, a human
        // has to file this one by hand.
        expect(byKey._vcr_fiscal_status).toBe('manual_required');
        expect(byKey._vcr_last_error).toContain('the order was charged');
        expect(byKey._vcr_last_error).toMatch(new RegExp(String(Math.round(Number(order.total)))));

        // And nothing was filed. Reporting the larger figure would be worse
        // than reporting nothing, because only a reversal can undo it.
        expect(salesCalls(await getMockLog())).toHaveLength(0);
    });

    test.describe('with tax calculation on', () => {
        // Prices include tax, which is how an Armenian store is set up: VAT is
        // extracted from the displayed price, never added to it. So a product
        // line's gross does not move, while shipping and fees — whose totals
        // WooCommerce treats as ex-tax — grow by the rate.
        test.beforeEach(async () => {
            await evalFile('configure-taxes.php', ['on']);
        });

        test.afterEach(async () => {
            // Load-bearing: the setting is global and the specs share one
            // WordPress, so leaving it on would silently change the
            // arithmetic of every order created after this file.
            await evalFile('configure-taxes.php', ['off']);
        });

        test('lines carry their tax portion and still add up to the charge', async () => {
            const order = JSON.parse(await evalFile('create-shaped-order.php', ['rich']));

            await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

            const byKey = await readVcrMeta(order.id);
            expect(byKey._vcr_fiscal_status).toBe('success');

            const calls = salesCalls(await getMockLog());
            expect(calls).toHaveLength(1);

            const { items } = calls[0].body;
            const lineSum = items.reduce(
                (sum, item) => sum + Number(item.price) * Number(item.quantity),
                0,
            );

            // The fold is what this pins: WooCommerce stores a line's net and
            // its tax separately, and the receipt has to report the gross.
            // Negative-tested by dropping `get_total_tax()` from
            // `unitPriceInclusive()` in the installed artefact — the order
            // does not quietly under-report, it lands in ManualRequired,
            // because the reconciliation guard catches the shortfall too. The
            // untaxed cases above stay green under that same break, which is
            // why this one has to exist.
            expect(lineSum).toBeCloseTo(Number(order.total), 2);

            // Tax is genuinely in play here, not silently zero.
            expect(Number(order.total)).toBeGreaterThan(3550);
        });
    });
});
