import { test, expect } from '@playwright/test';
import { logIn } from './helpers/admin.mjs';
import { evalFile, resetFiscalMeta, wpCli } from './helpers/wp-cli.mjs';
import { getMockLog, resetMockLog, resetMockPlan, setMockPlan } from './helpers/mock-vcr.mjs';

/**
 * E2E: the problem report, on the case it was built for.
 *
 * A store's bank charged the customer, the gateway never told WooCommerce,
 * and the order sat on "Pending payment" with no receipt. The plugin had
 * done nothing wrong and had nothing to show: no call had been made, so
 * there was no error, no log line and nothing on the VCR side either.
 *
 * The report has to make that visible: an unpaid order, no journal lines,
 * and no customer data. Then, once the order is marked paid, the journal
 * has to show the plugin picking it up.
 */
const SALE_RESPONSE = {
    status: 200,
    body: { urlId: 'rcpt-report-1', saleId: 91, crn: 'CRN-REPORT', srcReceiptId: 410, fiscal: 'FISCAL-REPORT' },
};

const BUYER_DETAILS = ['Anahit', 'Sargsyan', 'anahit.buyer@example.com', '37491234567', 'Abovyan'];

async function readReport(page, orderId) {
    await page.goto(`/wp-admin/admin.php?page=vcr-problem-report&order_id=${orderId}`);
    const text = await page.locator('#vcr-report-text').inputValue();

    return { text, report: JSON.parse(text) };
}

test.describe('VCR problem report', () => {
    test.beforeEach(async ({ page }) => {
        await resetMockLog();
        await resetMockPlan();
        await setMockPlan('registerSale', SALE_RESPONSE);
        await resetFiscalMeta();
        await logIn(page);
    });

    test('an order the gateway never confirmed: explained on the order, visible in the report', async ({ page }) => {
        const orderId = await evalFile('create-unpaid-order.php');
        expect(orderId).toMatch(/^\d+$/);

        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);
        expect((await getMockLog()).filter((entry) => entry.url === '/api/v1/sales')).toHaveLength(0);

        await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
        const box = page.locator('#vcr-fiscal-status');
        await expect(box).toContainText('WooCommerce has not been told that this order is paid');

        await box.getByRole('link', { name: 'Something wrong? Build a problem report' }).click();
        await expect(page.getByRole('heading', { name: 'VCR problem report' })).toBeVisible();
        await expect(page.locator('body')).toContainText('Nothing is sent until you press it');

        const { text, report } = await readReport(page, orderId);

        expect(report.order.id).toBe(Number(orderId));
        expect(report.order.status).toBe('pending');
        expect(report.order.paidAt).toBeNull();
        expect(report.order.fiscal.status).toBeNull();
        expect(report.journal).toEqual([]);
        expect(report.configuration.apiKeyConfigured).toBe(true);

        for (const detail of BUYER_DETAILS) {
            expect(text).not.toContain(detail);
        }
    });

    test('once the order is marked paid, the journal shows the plugin taking it', async ({ page }) => {
        const orderId = await evalFile('create-unpaid-order.php');

        await wpCli(['wc', 'shop_order', 'update', orderId, '--status=processing', '--user=admin']);
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        const { text, report } = await readReport(page, orderId);

        expect(report.order.status).toBe('processing');
        expect(report.order.fiscal.status).toBe('success');
        expect(report.order.fiscal.fiscalNumber).toBe('FISCAL-REPORT');

        const events = report.journal.map((line) => line.event);
        expect(events).toContain('order.hook');
        expect(events).toContain('order.queued');
        expect(events).toContain('order.attempt');
        expect(report.journal.find((line) => line.event === 'order.attempt').data.fiscal_status).toBe('success');
        expect(report.journal.every((line) => line.orderId === Number(orderId))).toBe(true);

        // The call that registered the sale is in the order's journal, under
        // the id the server answered with: that id is what support looks up.
        const saleCall = report.journal.find(
            (line) => line.event === 'api.call' && line.data.path === '/sales',
        );
        expect(saleCall.data).toMatchObject({ method: 'POST', status: 200, failure: null });
        expect(saleCall.traceId).toMatch(/^[0-9a-f]{32}$/);

        for (const detail of BUYER_DETAILS) {
            expect(text).not.toContain(detail);
        }
    });

    test('the downloaded file is the report on the screen', async ({ page }) => {
        const orderId = await evalFile('create-unpaid-order.php');
        const { report } = await readReport(page, orderId);

        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Download as a file' }).click();
        const download = await downloadPromise;

        expect(download.suggestedFilename()).toMatch(new RegExp(`^vcr-report-order-${orderId}-\\d{8}-\\d{6}\\.json$`));

        const stream = await download.createReadStream();
        const chunks = [];
        for await (const chunk of stream) {
            chunks.push(chunk);
        }
        const saved = JSON.parse(Buffer.concat(chunks).toString('utf8'));

        expect({ ...saved, generatedAt: null }).toEqual({ ...report, generatedAt: null });
    });

    test('wp vcr report prints the same report from a shell', async ({ page }) => {
        const orderId = await evalFile('create-unpaid-order.php');
        const { report } = await readReport(page, orderId);

        const printed = JSON.parse(await wpCli(['vcr', 'report', `--order=${orderId}`]));

        expect({ ...printed, generatedAt: null }).toEqual({ ...report, generatedAt: null });
    });

    test('sending takes a press, and what is sent has no buyer in it', async ({ page }) => {
        const orderId = await evalFile('create-unpaid-order.php');
        await wpCli(['wc', 'shop_order', 'update', orderId, '--status=processing', '--user=admin']);
        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        const { report } = await readReport(page, orderId);
        const reportsSent = async () =>
            (await getMockLog()).filter((entry) => entry.url === '/api/v1/support/reports');

        // Reading the report has sent nothing.
        expect(await reportsSent()).toHaveLength(0);

        await page.fill('#vcr-report-message', 'The customer paid at the bank and no receipt came out.');
        await page.fill('#vcr-report-contact', 'owner@shop.example');
        await page.click('#vcr-report-send');

        await expect(page.locator('.notice-success')).toContainText('7K4M-2QXD');

        const sent = await reportsSent();
        expect(sent).toHaveLength(1);

        const body = sent[0].body;
        expect(body.message).toBe('The customer paid at the bank and no receipt came out.');
        expect(body.contact).toBe('owner@shop.example');
        expect(body.context.order.id).toBe(Number(orderId));
        expect(body.context.plugin).toEqual(report.plugin);

        // The sale call's id is handed over, so support starts from it.
        const saleCall = report.journal.find((line) => line.event === 'api.call' && line.data.path === '/sales');
        expect(body.traceIds).toContain(saleCall.traceId);

        const raw = JSON.stringify(body);
        for (const detail of BUYER_DETAILS) {
            expect(raw).not.toContain(detail);
        }

        // And the order's journal now says a report went out.
        const after = await readReport(page, orderId);
        const line = after.report.journal.find((entry) => entry.event === 'report.sent');
        expect(line.data.reference).toBe('7K4M-2QXD');
    });
});
