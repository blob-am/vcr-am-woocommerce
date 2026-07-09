<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\AutoSettleTender;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\AutoSettle;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are diagnostic, surfaced via Logger or wp_die() (which escape themselves); per-arg esc_html on sprintf args is ritual noise.


/**
 * Map a {@see WC_Order}'s payment method onto the SDK's {@see AutoSettle}
 * shape — the single tender (`cash` vs `nonCash`) the VCR settles the whole
 * sale on. The decision is purely on the WC payment method id; mixed payments
 * and split tenders aren't modelled (single payment method per WC order is
 * the norm).
 *
 * Auto-settle, not an explicit AMD amount: the VCR derives the whole cart
 * total server-side from the (possibly foreign-currency) line items and
 * charges it to this tender. That is what makes foreign-currency sales work —
 * the plugin never needs to know the AMD total up front — and it removes the
 * "does the payment amount match the item sum?" rounding class of bug for AMD
 * stores too, since the settled amount *is* the item sum by construction.
 *
 * Default classification (built-in WC gateways that settle in person):
 *
 *   - `cod` (Cash on Delivery) -> cash
 *   - `cheque` (Check) -> cash  (treated as offline-tender)
 *   - everything else (Stripe / PayPal / WooPayments / WC's "BACS" bank
 *     transfer / etc.) -> nonCash
 *
 * Admins can extend the cash list via the `vcr_cash_payment_method_ids`
 * filter — an array of WC payment-method ids treated as cash. Useful for
 * regional gateways (Idram cash points, custom cash plugins) without
 * recompiling.
 *
 * Not declared `final` so unit tests can mock this mapper when testing
 * downstream orchestrators (FiscalJob) — there's no production extension
 * point.
 */
class PaymentMapper
{
    public function __construct(
        private readonly CashPaymentResolver $cashResolver = new CashPaymentResolver(),
    ) {
    }

    /**
     * @throws FiscalBuildException when the order total is non-positive
     *                              (zero or negative — nothing to fiscalise)
     */
    public function map(WC_Order $order): AutoSettle
    {
        // Zero-total orders (free trials, 100% coupons) aren't sales for
        // fiscal purposes — there's no money to record. Guard here, before
        // the VCR is asked to settle an empty cart.
        $total = (float) $order->get_total();

        if ($total <= 0.0) {
            throw new FiscalBuildException(sprintf(
                'Order #%d has a non-positive total (%s); nothing to fiscalise.',
                $order->get_id(),
                (string) $total,
            ));
        }

        $tender = $this->cashResolver->isCash($order->get_payment_method())
            ? AutoSettleTender::Cash
            : AutoSettleTender::NonCash;

        return new AutoSettle($tender);
    }
}
