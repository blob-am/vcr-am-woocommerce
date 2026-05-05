<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

/**
 * Bridges WC's `woocommerce_order_refunded` action to the
 * {@see RefundQueue}. Fires AFTER the refund is fully persisted in the
 * DB and AFTER WC's own internal post-refund logic (restock, download
 * revocation, etc.) — the canonical "do your post-processing" hook for
 * external integrations per WC core conventions.
 *
 * Why this hook and not the others:
 *
 *   - `woocommerce_create_refund` fires BEFORE save → if our SRC call
 *     succeeds but the refund DB write subsequently fails, we'd have an
 *     SRC record with no WC counterpart.
 *   - `woocommerce_refund_created` fires before some cleanup (restock,
 *     downloads); subtle ordering bugs lurk there.
 *   - `woocommerce_order_refunded` is the LAST hook in the refund
 *     creation flow and the one popular gateways (Stripe, PayPal-WC)
 *     listen on for the same reason.
 *
 * Hook signature: `do_action('woocommerce_order_refunded', $order_id, $refund_id)`.
 * We act on the refund_id and let the queue load the refund object —
 * keeps the listener's responsibility narrow ("translate hook to enqueue
 * call") and the queue's gates (refund-not-already-registered, etc.)
 * authoritative.
 */

if (! defined('ABSPATH')) {
    exit;
}

class OrderRefundedListener
{
    public function __construct(
        private readonly RefundQueue $queue,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_order_refunded', [$this, 'onRefunded'], 10, 2);
    }

    /**
     * @param mixed $orderId  unused — we identify the refund directly
     * @param mixed $refundId
     */
    public function onRefunded($orderId, $refundId): void
    {
        if (! is_int($refundId)) {
            // WC always passes int; defensive against custom callers
            // that hand-fire the action with garbage args.
            return;
        }

        $this->queue->enqueue($refundId);
    }
}
