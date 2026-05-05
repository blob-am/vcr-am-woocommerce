<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use WC_Order;
use WC_Order_Refund;

/**
 * Decides whether a freshly-created {@see WC_Order_Refund} can be
 * auto-registered with SRC as a full refund, or whether it must be
 * routed to {@see FiscalStatus::ManualRequired} for the admin to
 * handle.
 *
 * Two gates, both must pass:
 *
 *   1. **Parent sale must be Success with a saleId on record.**
 *      Refunds in SRC are addressed by `saleId`; if the parent sale
 *      hasn't successfully registered, we have no `saleId` to point
 *      to and SRC will reject the refund.
 *
 *   2. **Refund must be the FIRST and amount must equal parent total.**
 *      Phase 3e v0.1 supports only full refunds (see
 *      {@see RefundEligibility} class doc-block for why). The combo
 *      "first refund + amount equals total" is the unambiguous
 *      "refund this whole order" signal — anything else (second
 *      refund on the same order, or amount < total) is partial and
 *      gets a clear ManualRequired message instead of a wrong
 *      auto-decision.
 */
class RefundEligibilityChecker
{
    /**
     * Tolerance for refund-total vs order-total float comparison —
     * smaller than the smallest currency subunit so legitimate equal-
     * total refunds don't get rejected for trailing-zero precision
     * drift.
     *
     * 0.005 is correct for AMD (zero subunit) and 2-decimal-place
     * currencies (USD/EUR/RUB cents at 0.01 minimum).
     *
     * KNOWN LIMITATION: For 3-decimal-place currencies (BHD, JOD, KWD,
     * OMR, TND) the smallest subunit is 0.001, so 0.005 is larger than
     * the subunit and could swallow real partial refunds as if they
     * were full. The plugin is Armenia-focused and these currencies
     * are vanishingly rare in WC stores selling into AMD; document
     * here rather than parameterise. If a future merchant needs 3dp
     * currency support, narrow this to 0.0005 (or per-currency).
     */
    private const AMOUNT_EPSILON = 0.005;

    public function __construct(
        private readonly FiscalStatusMeta $fiscalMeta,
    ) {
    }

    public function check(WC_Order_Refund $refund, WC_Order $parent): RefundEligibility
    {
        // Gate 1: parent must have a successful fiscal record with a
        // server-side saleId we can reference. Anything else means
        // SRC has no record of the sale to refund.
        $parentStatus = $this->fiscalMeta->status($parent);
        if ($parentStatus !== FiscalStatus::Success) {
            $statusLabel = $parentStatus !== null ? $parentStatus->value : 'not_enqueued';

            return RefundEligibility::ineligible(sprintf(
                /* translators: 1: current fiscal status of the parent order */
                __('Cannot auto-register refund: parent order is in fiscal status "%s", not "success". Wait for the parent sale to register with SRC, then retry this refund.', 'vcr'),
                $statusLabel,
            ));
        }

        $saleId = $this->fiscalMeta->saleId($parent);
        if ($saleId === null) {
            // Defensive — `markSuccess()` always writes saleId, so we'd
            // only get here on corrupted/restored data.
            return RefundEligibility::ineligible(__(
                'Cannot auto-register refund: parent order has no SRC saleId on record despite being marked successful. The order metadata may be corrupted — re-fiscalise the parent order first.',
                'vcr',
            ));
        }

        // Gate 2: this must be the only refund on the parent, and its
        // amount must equal the parent total. Anything else is partial.
        $refunds = $parent->get_refunds();
        if (count($refunds) > 1) {
            return RefundEligibility::ineligible(__(
                'Cannot auto-register refund: this order already has another refund. The VCR plugin (v0.1) only supports a single full refund per order automatically — additional refunds must be processed manually with SRC.',
                'vcr',
            ));
        }

        $refundAmount = (float) $refund->get_amount();
        $parentTotal = (float) $parent->get_total();

        if (abs($refundAmount - $parentTotal) > self::AMOUNT_EPSILON) {
            return RefundEligibility::ineligible(sprintf(
                /* translators: 1: refund amount, 2: parent order total */
                __('Cannot auto-register refund: refund amount %1$s does not equal order total %2$s. The VCR plugin (v0.1) only supports full refunds automatically — partial refunds must be processed manually with SRC.', 'vcr'),
                $refund->get_amount(),
                $parent->get_total(),
            ));
        }

        return RefundEligibility::full();
    }
}
