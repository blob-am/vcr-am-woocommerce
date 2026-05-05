<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Fiscal\CashPaymentResolver;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RefundAmount;
use WC_Order;
use WC_Order_Refund;

/**
 * Maps a {@see WC_Order_Refund}'s amount onto the SDK's {@see RefundAmount}
 * — the cash/nonCash counterpart of {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\PaymentMapper}.
 *
 * The cash-vs-nonCash classification is inherited from the *parent*
 * order's payment method via {@see CashPaymentResolver}: a refund is
 * money flowing back through the same channel the original payment used.
 * If the customer paid with `cod` (cash on delivery), the refund is cash;
 * if they paid with Stripe, the refund is nonCash. The plugin doesn't
 * model "refund via different tender" because WC itself doesn't surface
 * that — refunds are tied to the original payment method.
 *
 * Not declared `final` so unit tests for {@see RefundJob} can mock it.
 */
class RefundPaymentMapper
{
    public function __construct(
        private readonly CashPaymentResolver $cashResolver = new CashPaymentResolver(),
    ) {
    }

    /**
     * @throws FiscalBuildException when the refund amount is non-positive
     *                              (zero or negative refund makes no fiscal sense).
     */
    public function map(WC_Order $parent, WC_Order_Refund $refund): RefundAmount
    {
        $amountString = $this->refundAmountString($refund);

        if ($this->cashResolver->isCash($parent->get_payment_method())) {
            return new RefundAmount(cash: $amountString);
        }

        return new RefundAmount(nonCash: $amountString);
    }

    /**
     * `WC_Order_Refund::get_amount()` returns the absolute refund total
     * as a non-negative decimal string. We canonicalise it to the same
     * shape the SDK expects (`/^\d+(\.\d+)?$/` per RefundAmount validation).
     */
    private function refundAmountString(WC_Order_Refund $refund): string
    {
        $amount = (float) $refund->get_amount();

        if ($amount <= 0.0) {
            throw new FiscalBuildException(sprintf(
                'Refund #%d has a non-positive amount (%s); nothing to register with SRC.',
                $refund->get_id(),
                (string) $amount,
            ));
        }

        $formatted = number_format($amount, 2, '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim($formatted, '0');
            $formatted = rtrim($formatted, '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }
}
