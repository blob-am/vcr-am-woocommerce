<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Action Scheduler integration: enqueues a fiscalisation attempt for a WC
 * order, dispatches the {@see FiscalJob} when the action fires, and
 * schedules the next attempt on a fixed exponential-ish backoff if the
 * job asks for a retry.
 *
 * Why Action Scheduler:
 *
 *   - It ships with WooCommerce core (since 3.0) so we don't add a
 *     dependency.
 *   - Persists across requests and worker restarts in `wp_actionscheduler_*`
 *     tables — a hot-reload of the plugin doesn't lose pending retries.
 *   - Has built-in admin UI (WooCommerce -> Status -> Scheduled Actions)
 *     which is a free observability win.
 *   - Honours WP-Cron OR a system cron pointed at WP-Cron — admins can
 *     pick their reliability tier without code changes here.
 *
 * Backoff schedule and max attempts:
 *
 *   - {@see self::RETRY_DELAYS_SECONDS} has one entry per retry slot, so
 *     its length is `FiscalJob::MAX_ATTEMPTS - 1`. The job side is the
 *     authoritative arbiter of "stop retrying" — once the queue gets
 *     back a non-retriable outcome (including job-side max-attempts
 *     enforcement), no further actions are scheduled.
 *
 * Idempotency:
 *
 *   - {@see self::enqueue()} skips if there's already a scheduled action
 *     for the same order, or if the order's meta status indicates the
 *     fiscalisation has already finished (Success / Failed / ManualRequired).
 *     The "Failed" / "ManualRequired" terminal states intentionally require
 *     an admin "Fiscalize now" action (Phase 3c) to re-enqueue, so we
 *     don't silently retry orders the admin has flagged for review.
 *
 *   - The dedup check considers BOTH `pending` and `in-progress` AS
 *     statuses. WC's `payment_complete()` fires
 *     `woocommerce_payment_complete` and `woocommerce_order_status_processing`
 *     in quick succession from the same call site; without the
 *     `in-progress` half of the check, a slow first attempt that
 *     reaches AS's "claimed/processing" state before the second hook
 *     fires would let the second `enqueue()` slip through and we'd
 *     transmit the same sale to SRC twice. `as_has_scheduled_action`
 *     only checks pending (documented behaviour as of AS 3.x), so the
 *     more-flexible `as_get_scheduled_actions` is required here.
 *
 *     Residual race: if both hooks fire in the same microsecond and
 *     neither has yet committed an AS row, both will pass the dedup
 *     check. This is microscopic in practice; the proper defence is
 *     SDK-side idempotency keying (the SDK accepts an `Idempotency-Key`
 *     header derived from the order's stable external id) — out of
 *     scope for this WP-side fix.
 */
/**
 * Not declared `final` so the OrderListener unit tests can mock the queue —
 * there's no production extension point.
 */
class FiscalQueue
{
    public const ACTION_HOOK = 'vcr_fiscalize_order';

    public const ACTION_GROUP = 'vcr';

    /**
     * Delay before each subsequent attempt, in seconds. Index 0 is the
     * delay between attempt 1 and attempt 2; the last entry is the delay
     * between the second-to-last and the final attempt.
     *
     *   15s, 1m, 5m, 30m, 2h
     *
     * Combined with {@see FiscalJob::MAX_ATTEMPTS} = 6, this means a
     * persistently failing order is retried over a window of ~2.5 hours
     * before going terminal. The shape (short -> long) optimises for
     * transient hiccups (most failures resolve in seconds) while still
     * covering longer SRC outages.
     *
     * @var list<int>
     */
    private const RETRY_DELAYS_SECONDS = [15, 60, 300, 1800, 7200];

    public function __construct(
        private readonly FiscalJob $job,
        private readonly FiscalStatusMeta $meta,
    ) {
    }

    public function register(): void
    {
        add_action(self::ACTION_HOOK, [$this, 'handle']);
    }

    /**
     * Enqueue the first fiscalisation attempt for an order. Safe to call
     * multiple times — it's a no-op if the order is already fiscalised,
     * already terminally failed, or already has a queued action.
     */
    public function enqueue(int $orderId): void
    {
        $order = wc_get_order($orderId);

        // WC_Order_Refund extends WC_Order, so a bare `instanceof` check
        // accepts refunds. Filter on order type instead — only true shop
        // orders are fiscalisable here (refunds get their own listener
        // and SDK call in Phase 3e).
        if (! $order instanceof WC_Order || $order->get_type() !== 'shop_order') {
            return;
        }

        $status = $this->meta->status($order);

        // Terminal states require explicit admin intervention to re-enqueue
        // (Phase 3c "Fiscalize now" button). Auto-enqueue from WC hooks
        // never overrides that decision.
        if ($status !== null && $status->isTerminal()) {
            return;
        }

        if ($this->hasScheduledAction($orderId)) {
            return;
        }

        $this->meta->initialize($order);

        as_enqueue_async_action(self::ACTION_HOOK, [$orderId], self::ACTION_GROUP);
    }

    /**
     * Action Scheduler entry point. Args from
     * {@see as_enqueue_async_action()} / {@see as_schedule_single_action()}
     * are passed as positional arguments — we registered with a single
     * `[$orderId]` arg list, so the handler takes one int.
     */
    public function handle(mixed $orderId): void
    {
        if (! is_int($orderId)) {
            // Defensive: a misregistered action with garbage args
            // shouldn't crash the queue runner.
            return;
        }

        $outcome = $this->job->run($orderId);

        if (! $outcome->shouldRetry()) {
            return;
        }

        $this->scheduleNextRetry($orderId);
    }

    private function scheduleNextRetry(int $orderId): void
    {
        $order = wc_get_order($orderId);

        // Same refund/subtype guard as `enqueue()`. The order id reaches
        // here from a FiscalJob run that already filtered, so this is
        // belt-and-braces — but if FiscalJob is ever bypassed, we still
        // refuse to schedule retries against the wrong order kind.
        if (! $order instanceof WC_Order || $order->get_type() !== 'shop_order') {
            return;
        }

        $attempt = $this->meta->attemptCount($order);

        // `attempt` is 1-based and counts attempts already completed.
        // The delay slot for "schedule the next one" is therefore
        // `attempt - 1`. FiscalJob caps at MAX_ATTEMPTS and marks Failed
        // beyond that — so this should always be in range, but defend
        // the array bounds in case the job side changes independently.
        $slot = $attempt - 1;

        if (! isset(self::RETRY_DELAYS_SECONDS[$slot])) {
            return;
        }

        as_schedule_single_action(
            time() + self::RETRY_DELAYS_SECONDS[$slot],
            self::ACTION_HOOK,
            [$orderId],
            self::ACTION_GROUP,
        );
    }

    private function hasScheduledAction(int $orderId): bool
    {
        if (! function_exists('as_get_scheduled_actions')) {
            // WC < 4.0 had a different helper surface. We require WC >= 6
            // in the plugin headers, so this branch is theoretical — but
            // cheap to guard rather than to debug post-deploy.
            return false;
        }

        // Multi-status filter — see class doc-block "Idempotency" for
        // the rationale. `pending` covers the queued case; `in-progress`
        // covers the case where the first attempt is mid-execution when
        // a sibling hook fires. Without `in-progress`, the same sale
        // can be transmitted to SRC twice during WC's payment_complete()
        // hook chain.
        //
        // `per_page => 1` because we only need a presence signal; we
        // never read the rows themselves.
        $matches = as_get_scheduled_actions(
            [
                'hook' => self::ACTION_HOOK,
                'args' => [$orderId],
                'group' => self::ACTION_GROUP,
                'status' => ['pending', 'in-progress'],
                'per_page' => 1,
            ],
            'ids',
        );

        return is_array($matches) && $matches !== [];
    }
}
