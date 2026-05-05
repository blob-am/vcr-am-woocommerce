<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\SaleAmount;
use WC_Order;

/**
 * Map a {@see WC_Order}'s payment method onto the SDK's {@see SaleAmount}
 * shape — `cash` vs `nonCash`. The decision is purely on the WC payment
 * method id; mixed payments and split tenders aren't modelled (single
 * payment method per WC order is the norm).
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
 * Open intentionally:
 *   - `prepayment` and `compensation` buckets are not used yet; the
 *     prepayment flow has its own SDK endpoint and will get its own
 *     mapper in a later phase.
 */
/**
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

    public function map(WC_Order $order): SaleAmount
    {
        $totalString = $this->orderTotalString($order);

        if ($this->cashResolver->isCash($order->get_payment_method())) {
            return new SaleAmount(cash: $totalString);
        }

        return new SaleAmount(nonCash: $totalString);
    }

    private function orderTotalString(WC_Order $order): string
    {
        $total = (float) $order->get_total();

        if ($total <= 0.0) {
            // Zero-total orders (free trials, 100% coupons) aren't sales
            // for fiscal purposes — there's no money to record.
            throw new FiscalBuildException(sprintf(
                'Order #%d has a non-positive total (%s); nothing to fiscalise.',
                $order->get_id(),
                (string) $total,
            ));
        }

        $formatted = number_format($total, 2, '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim($formatted, '0');
            $formatted = rtrim($formatted, '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }
}
