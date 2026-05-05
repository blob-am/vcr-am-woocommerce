import { test, expect } from '@playwright/test';
import { wpCli, wpCliJson } from './helpers/wp-cli.mjs';
import { resetMockLog, setMockPlan, getMockLog } from './helpers/mock-vcr.mjs';

/**
 * E2E: failure → retry → terminal Failed.
 *
 * Programs the mock VCR server to return HTTP 503 on every
 * registerSale call, creates a paid order, and exhausts the plugin's
 * MAX_ATTEMPTS retry budget. Asserts the meta lifecycle:
 *
 *   - Attempt 1 → status `pending`, attempt_count = 1, last_error set
 *   - Attempt 2..N → status `pending`, attempt_count grows
 *   - Attempt MAX_ATTEMPTS → status `failed`, message includes "Gave up"
 *
 * Why this matters:
 *   - Validates the FiscalJob 5xx classification → retry path
 *   - Validates the FiscalQueue::scheduleNextRetry chain works
 *   - Validates the markFailed transition fires on budget exhaustion
 *
 * Implementation note on time-based retry:
 *   Action Scheduler schedules each retry with a delay (15s..2h). To
 *   run them all in one test we advance `scheduled_date_gmt` to NOW
 *   between each `action-scheduler run` call. A pure
 *   `--force` flag isn't enough — AS still respects the action's own
 *   `scheduled_date` even with `--force`.
 */
test.describe('VCR fiscal flow (failure → retry → Failed)', () => {
    test.beforeEach(async () => {
        await resetMockLog();
        await setMockPlan('registerSale', {
            status: 503,
            body: { error: 'gateway timeout (mock)' },
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

    test('persistent 5xx exhausts retry budget and ends in Failed', async () => {
        const orderId = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/create-paid-order.php',
        ]);
        expect(orderId).toMatch(/^\d+$/);

        // FiscalJob::MAX_ATTEMPTS = 6. We need to run the queue 6 times,
        // pulling each subsequent retry forward in time so AS will pick
        // it up on the next dispatch.
        const MAX_ATTEMPTS = 6;
        for (let i = 0; i < MAX_ATTEMPTS; i++) {
            // First iteration: the action is already scheduled at
            // enqueue time (NOW). After that, scheduleNextRetry
            // schedules MORE actions in the future — pull them forward.
            if (i > 0) {
                await wpCli([
                    'db', 'query',
                    "UPDATE wp_actionscheduler_actions SET scheduled_date_gmt = UTC_TIMESTAMP(), scheduled_date_local = NOW() WHERE hook = 'vcr_fiscalize_order' AND status = 'pending'",
                ]);
            }
            await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);
        }

        // After 6 attempts, status MUST be failed — markFailed has the
        // "Gave up after %d attempts" message.
        const meta = await wpCliJson(['post', 'meta', 'list', orderId]);
        const byKey = Object.fromEntries(
            meta.filter((row) => row.meta_key.startsWith('_vcr_'))
                .map((row) => [row.meta_key, row.meta_value]),
        );

        expect(byKey._vcr_fiscal_status).toBe('failed');
        expect(byKey._vcr_attempt_count).toBe(String(MAX_ATTEMPTS));
        expect(byKey._vcr_last_error).toContain('Gave up after');

        // Mock should have seen at least MAX_ATTEMPTS POSTs to /sales.
        // (It might be MORE if a retry happened to fire mid-test; lower
        // bound is enough — the meta assertion is the contract.)
        const log = await getMockLog();
        const salesCalls = log.filter(
            (entry) => entry.url === '/api/v1/sales' && entry.method === 'POST',
        );
        expect(salesCalls.length).toBeGreaterThanOrEqual(MAX_ATTEMPTS);
    });

    test('first failure leaves order in Pending with attempt_count=1 and last_error captured', async () => {
        // Single-attempt scenario — proves the markRetriableFailure
        // path stamps the meta correctly without needing the full
        // budget exhaustion. Faster than the previous test for
        // catching regression in the per-attempt bookkeeping.
        const orderId = await wpCli([
            'eval-file',
            'wp-content/plugins/vcr-am-woocommerce/tests/E2E/scripts/create-paid-order.php',
        ]);

        await wpCli(['action-scheduler', 'run', '--hooks=vcr_fiscalize_order', '--force']);

        const meta = await wpCliJson(['post', 'meta', 'list', orderId]);
        const byKey = Object.fromEntries(
            meta.filter((row) => row.meta_key.startsWith('_vcr_'))
                .map((row) => [row.meta_key, row.meta_value]),
        );

        expect(byKey._vcr_fiscal_status).toBe('pending');
        expect(byKey._vcr_attempt_count).toBe('1');
        expect(byKey._vcr_last_error).toContain('VCR API HTTP 503');
    });
});
