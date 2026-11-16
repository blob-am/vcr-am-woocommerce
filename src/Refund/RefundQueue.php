<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJobOutcome;
use BlobSolutions\WooCommerceVcrAm\Journal\DatabaseStore;
use BlobSolutions\WooCommerceVcrAm\Journal\Journal;
use WC_Order_Refund;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Action Scheduler integration for refund registration. Mirrors
 * {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue} for sales:
 * shares the same `vcr` AS group (so admins see one unified scheduled-
 * actions view), shares the same backoff schedule, but uses a separate
 * action hook so refund and sale handlers don't cross-trigger.
 *
 * Why a separate queue class instead of one generic `Queue<T>`:
 *
 *   - Action Scheduler dispatches by hook name, not type parameter.
 *   - The `enqueue` argument shape differs (refund_id vs order_id) and
 *     the type filtering differs (`WC_Order_Refund` vs `WC_Order`).
 *   - Tests for each direction stay focused on their own happy path
 *     instead of weaving through generic abstractions.
 *
 * The duplication with FiscalQueue is real but the alternative — a
 * shared base class — would force each test to mock the abstraction
 * layer instead of the concrete behaviour, with no win in correctness.
 *
 * Not declared `final` so {@see OrderRefundedListener} unit tests can
 * mock the queue.
 */
class RefundQueue
{
    public const ACTION_HOOK = 'vcr_register_refund';

    /** Same group as the sale queue — one place admins look. */
    public const ACTION_GROUP = 'vcr';

    /**
     * Same backoff schedule as the sale queue. Refund retries don't
     * have stale-skip (see {@see RefundJob} class doc-block) but the
     * per-attempt cadence matches so admins reading docs / scheduled-
     * action UI see one consistent retry shape.
     *
     * @var list<int>
     */
    private const RETRY_DELAYS_SECONDS = [15, 60, 300, 1800, 7200];

    public function __construct(
        private readonly RefundJob $job,
        private readonly RefundStatusMeta $meta,
        private readonly Journal $journal = new Journal(new DatabaseStore()),
    ) {
    }

    public function register(): void
    {
        add_action(self::ACTION_HOOK, [$this, 'handle']);
    }

    /**
     * Enqueue the first refund-registration attempt. Safe to call
     * multiple times — no-op if already registered, terminally failed,
     * or already queued.
     */
    public function enqueue(int $refundId): void
    {
        $refund = wc_get_order($refundId);

        if (! $refund instanceof WC_Order_Refund) {
            $this->journal->record('refund.skipped', null, ['refund_id' => $refundId, 'reason' => 'not_a_refund']);

            return;
        }

        $orderId = $refund->get_parent_id();
        $status = $this->meta->status($refund);

        if ($status !== null && $status->isTerminal()) {
            // Terminal — admin's job to retry via "Register refund now"
            // (Phase 3e UI). Don't auto-re-enqueue.
            $this->journal->record('refund.skipped', $orderId, [
                'refund_id' => $refundId,
                'reason' => 'already_settled',
                'fiscal_status' => $status->value,
            ]);

            return;
        }

        if ($this->hasScheduledAction($refundId)) {
            $this->journal->record('refund.skipped', $orderId, ['refund_id' => $refundId, 'reason' => 'already_queued']);

            return;
        }

        $this->meta->initialize($refund);

        as_enqueue_async_action(self::ACTION_HOOK, [$refundId], self::ACTION_GROUP);

        $this->journal->record('refund.queued', $orderId, ['refund_id' => $refundId]);
    }

    /**
     * Action Scheduler entry point.
     */
    public function handle(mixed $refundId): void
    {
        if (! is_int($refundId)) {
            return;
        }

        $refund = wc_get_order($refundId);
        // Journal lines are filed under the order the refund belongs to, so
        // that an order's report tells the whole story. An id that is not a
        // refund has no order to file under.
        $orderId = $refund instanceof WC_Order_Refund ? $refund->get_parent_id() : null;

        $outcome = $this->journal->during($orderId, fn (): FiscalJobOutcome => $this->job->run($refundId));

        $this->journal->record('refund.attempt', $orderId, [
            'refund_id' => $refundId,
            'fiscal_status' => $outcome->status->value,
            'reason' => $outcome->reason,
        ]);

        if (! $outcome->shouldRetry() || ! $refund instanceof WC_Order_Refund) {
            return;
        }

        $this->scheduleNextRetry($refund);
    }

    private function scheduleNextRetry(WC_Order_Refund $refund): void
    {
        $refundId = $refund->get_id();
        $orderId = $refund->get_parent_id();
        $attempt = $this->meta->attemptCount($refund);
        $slot = $attempt - 1;

        if (! isset(self::RETRY_DELAYS_SECONDS[$slot])) {
            $this->journal->record('refund.retries_exhausted', $orderId, [
                'refund_id' => $refundId,
                'attempts' => $attempt,
            ]);

            return;
        }

        as_schedule_single_action(
            time() + self::RETRY_DELAYS_SECONDS[$slot],
            self::ACTION_HOOK,
            [$refundId],
            self::ACTION_GROUP,
        );

        $this->journal->record('refund.retry_scheduled', $orderId, [
            'refund_id' => $refundId,
            'attempts' => $attempt,
            'delay_seconds' => self::RETRY_DELAYS_SECONDS[$slot],
        ]);
    }

    private function hasScheduledAction(int $refundId): bool
    {
        if (! function_exists('as_get_scheduled_actions')) {
            return false;
        }

        // Multi-status filter (pending + in-progress) to prevent
        // double-registering a refund when WC fires
        // `woocommerce_order_refunded` and a sibling hook in close
        // succession. Same rationale as the matching check in
        // {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue::hasScheduledAction()}
        // — see that class's "Idempotency" doc-block.
        $matches = as_get_scheduled_actions(
            [
                'hook' => self::ACTION_HOOK,
                'args' => [$refundId],
                'group' => self::ACTION_GROUP,
                'status' => ['pending', 'in-progress'],
                'per_page' => 1,
            ],
            'ids',
        );

        return is_array($matches) && $matches !== [];
    }
}
