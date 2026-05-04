<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

/**
 * Wire WooCommerce order-state events to {@see FiscalQueue::enqueue()}.
 *
 * Hook selection rationale:
 *
 *   - **`woocommerce_payment_complete($order_id)`** — fires when a
 *     payment gateway calls `WC_Order::payment_complete()`. This is the
 *     canonical "money is in" signal for online gateways (Stripe, PayPal,
 *     Idram, etc.), regardless of which final order status the gateway
 *     promotes the order to.
 *
 *   - **`woocommerce_order_status_processing`** — fires when an order
 *     transitions to "processing". Some gateways skip
 *     `payment_complete()` and just set processing directly; this is the
 *     belt to `payment_complete`'s braces.
 *
 *   - **`woocommerce_order_status_completed`** — admins can manually
 *     mark an order Completed in the dashboard (typical for digital
 *     downloads or "verified COD"). This catches the manual path so
 *     fiscalisation triggers regardless of how Completed was reached.
 *
 * Each of these will fire at least once for a paid order; many
 * combinations fire two or three times. Idempotency lives in
 * {@see FiscalQueue::enqueue()} (which de-dupes against existing
 * scheduled actions) and {@see FiscalJob::run()} (which short-circuits on
 * already-Success), so multiple-fire is harmless.
 *
 * On-Hold and Pending Payment are intentionally NOT hooked:
 *
 *   - On-Hold means the gateway is waiting for confirmation (BACS bank
 *     transfer, off-line check) — fiscalising before money clears would
 *     produce a fictitious receipt.
 *   - Pending Payment means the customer hasn't paid yet.
 *
 * Refunds and cancellations are out of scope here — they're a separate
 * SDK call ({@see \BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient::registerSaleRefund()})
 * with their own listener (Phase 3e).
 */
final class OrderListener
{
    public function __construct(
        private readonly FiscalQueue $queue,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_payment_complete', [$this, 'onPaymentComplete']);
        add_action('woocommerce_order_status_processing', [$this, 'onProcessingOrCompleted']);
        add_action('woocommerce_order_status_completed', [$this, 'onProcessingOrCompleted']);
    }

    public function onPaymentComplete(mixed $orderId): void
    {
        $this->dispatch($orderId);
    }

    public function onProcessingOrCompleted(mixed $orderId): void
    {
        $this->dispatch($orderId);
    }

    private function dispatch(mixed $orderId): void
    {
        // WC's hook signatures historically passed the order id as int,
        // but plugins/old themes occasionally call do_action() with a
        // string id. Coerce defensively and bail on anything else.
        if (is_int($orderId)) {
            $this->queue->enqueue($orderId);

            return;
        }

        if (is_string($orderId) && ctype_digit($orderId)) {
            $this->queue->enqueue((int) $orderId);
        }
    }
}
