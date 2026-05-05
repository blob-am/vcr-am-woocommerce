<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Receipt\ReceiptUrlBuilder;
use WC_Order;
use WC_Order_Refund;

/**
 * Builds the public-facing receipt URL for a successfully-registered
 * refund: same `{host}/{locale}/r/{crn}/{urlId}` shape served by the
 * VCR Next.js receipt page, but with the refund's own `crn` + `urlId`
 * (issued by SRC at refund-registration time, distinct from the parent
 * sale's pair).
 *
 * Composition over duplication: shares host derivation, locale
 * resolution, and URL assembly with {@see ReceiptUrlBuilder} via that
 * class's `composeUrl()` helper. The locale signal is read from the
 * *parent* order — refunds don't carry their own customer language;
 * the customer is the same person who placed the original order.
 *
 * Fires its own `vcr_refund_receipt_url` filter so stores can override
 * refund URLs independently of sale URLs (e.g., a store that hosts
 * sale receipts on vcr.am but routes refund inquiries through their
 * own support portal).
 */
class RefundReceiptUrlBuilder
{
    public function __construct(
        private readonly ReceiptUrlBuilder $sharedBuilder,
        private readonly RefundStatusMeta $meta,
    ) {
    }

    public function build(WC_Order_Refund $refund): ?string
    {
        if ($this->meta->status($refund) !== FiscalStatus::Success) {
            return null;
        }

        $crn = $this->meta->crn($refund);
        $urlId = $this->meta->urlId($refund);

        // CRN can be empty string if SRC didn't issue one at registration
        // time (per RegisterSaleRefundResponse contract — see the SDK's
        // class doc-block for why crn/fiscal are nullable on refunds).
        // Without a CRN, the receipt route can't be addressed.
        if ($crn === null || $urlId === null) {
            return null;
        }

        $parent = wc_get_order($refund->get_parent_id());

        // Without a parent we have no locale context — bail rather than
        // synthesise a "default" URL that would point the customer at
        // the wrong language.
        if (! $parent instanceof WC_Order) {
            return null;
        }

        $url = $this->sharedBuilder->composeUrl($crn, $urlId, $parent);

        $filtered = apply_filters('vcr_refund_receipt_url', $url, $refund);

        return is_string($filtered) && $filtered !== '' ? $filtered : $url;
    }
}
