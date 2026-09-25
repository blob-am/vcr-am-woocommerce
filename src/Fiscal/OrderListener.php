<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Configuration;

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
 *     Cash on delivery lands here at checkout, before the courier has
 *     collected anything. That is lawful — Government Decision 1976-N
 *     Annex 3 §3(2) lets an order-based delivery seller generate the
 *     receipt in advance so long as it exists before the goods leave the
 *     delivery point — but a refused delivery then costs a reversal
 *     receipt. A shop that would rather wait sets
 *     {@see \BlobSolutions\WooCommerceVcrAm\Configuration::OPT_CASH_FISCALIZE_ON}
 *     to `completed`, and cash orders are skipped here.
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

if (! defined('ABSPATH')) {
    exit;
}

final class OrderListener
{
    public function __construct(
        private readonly FiscalQueue $queue,
        private readonly Configuration $configuration,
        private readonly CashPaymentResolver $cashResolver,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_payment_complete', [$this, 'onPaymentComplete']);
        add_action('woocommerce_order_status_processing', [$this, 'onProcessing']);
        add_action('woocommerce_order_status_completed', [$this, 'onCompleted']);
    }

    public function onPaymentComplete(mixed $orderId): void
    {
        // A gateway calling payment_complete() has the money, whatever the
        // tender is nominally called. Never deferred.
        $this->dispatch($orderId);
    }

    public function onProcessing(mixed $orderId): void
    {
        if ($this->shouldDeferCashOrder($orderId)) {
            return;
        }

        $this->dispatch($orderId);
    }

    public function onCompleted(mixed $orderId): void
    {
        $this->dispatch($orderId);
    }

    /**
     * True when this is a cash-tender order and the shop has chosen to
     * fiscalise cash only once the order is marked Completed.
     *
     * Deliberately fails open: if the order cannot be loaded, or the tender
     * cannot be determined, the sale is queued. An extra receipt is
     * reversible; a receipt that was never filed is not.
     */
    private function shouldDeferCashOrder(mixed $orderId): bool
    {
        if ($this->configuration->cashFiscalizeOn() !== Configuration::CASH_FISCALIZE_ON_COMPLETED) {
            return false;
        }

        $id = self::toOrderId($orderId);

        if ($id === null) {
            return false;
        }

        $order = wc_get_order($id);

        if (! $order instanceof \WC_Order) {
            return false;
        }

        return $this->cashResolver->isCash($order->get_payment_method());
    }

    private function dispatch(mixed $orderId): void
    {
        $id = self::toOrderId($orderId);

        if ($id !== null) {
            $this->queue->enqueue($id);
        }
    }

    /**
     * WC's hook signatures historically passed the order id as int, but
     * plugins and old themes occasionally call do_action() with a string id.
     * Coerce defensively and return null for anything else.
     */
    private static function toOrderId(mixed $orderId): ?int
    {
        if (is_int($orderId)) {
            return $orderId;
        }

        if (is_string($orderId) && ctype_digit($orderId)) {
            return (int) $orderId;
        }

        return null;
    }
}
