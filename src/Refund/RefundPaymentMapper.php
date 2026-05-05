<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Currency\CurrencyConverter;
use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;
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
        private readonly ?CurrencyConverter $converter = null,
        private readonly CashPaymentResolver $cashResolver = new CashPaymentResolver(),
    ) {
    }

    /**
     * @throws FiscalBuildException when the refund amount is non-positive
     *                              (zero or negative refund makes no fiscal sense),
     *                              or when AMD conversion is required but unavailable.
     */
    public function map(WC_Order $parent, WC_Order_Refund $refund): RefundAmount
    {
        $amountString = $this->refundAmountString($parent, $refund);

        if ($this->cashResolver->isCash($parent->get_payment_method())) {
            return new RefundAmount(cash: $amountString);
        }

        return new RefundAmount(nonCash: $amountString);
    }

    /**
     * `WC_Order_Refund::get_amount()` returns the absolute refund total
     * as a non-negative decimal string. We canonicalise it to the same
     * shape the SDK expects (`/^\d+(\.\d+)?$/` per RefundAmount validation).
     *
     * Multi-currency: refunds inherit the parent order's currency. We
     * convert through the same {@see CurrencyConverter} the sale used so
     * the refund magnitude on the SRC side matches the original receipt
     * line-for-line (modulo intervening rate movement, which is
     * unavoidable — SRC accepts that).
     */
    private function refundAmountString(WC_Order $parent, WC_Order_Refund $refund): string
    {
        $amount = (float) $refund->get_amount();

        if ($amount <= 0.0) {
            throw new FiscalBuildException(sprintf(
                'Refund #%d has a non-positive amount (%s); nothing to register with SRC.',
                $refund->get_id(),
                (string) $amount,
            ));
        }

        $amd = $this->convertToAmd($parent, $refund, $amount);

        $formatted = number_format($amd, 2, '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim($formatted, '0');
            $formatted = rtrim($formatted, '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @throws FiscalBuildException
     */
    private function convertToAmd(WC_Order $parent, WC_Order_Refund $refund, float $amount): float
    {
        $currency = strtoupper($parent->get_currency());

        if ($this->converter === null) {
            if ($currency !== '' && $currency !== CurrencyConverter::HOME_CURRENCY) {
                throw new FiscalBuildException(sprintf(
                    'Refund #%d (parent order #%d) is in %s but no CurrencyConverter is configured. Refusing to register a refund whose magnitude would not match the parent receipt.',
                    $refund->get_id(),
                    $parent->get_id(),
                    $currency,
                ));
            }

            return $amount;
        }

        try {
            return $this->converter->toAmd($amount, $currency);
        } catch (ExchangeRateUnavailableException $e) {
            throw new FiscalBuildException(sprintf(
                'Cannot convert refund #%d (%s) to AMD: %s',
                $refund->get_id(),
                $currency,
                $e->getMessage(),
            ), previous: $e);
        }
    }
}
