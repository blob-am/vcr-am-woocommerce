<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Derives the optional merchant-internal comment attached to a fiscalised
 * sale, so a VCR receipt can be reconciled back to the WooCommerce order that
 * produced it.
 *
 * The comment is internal to the merchant: VCR never prints it on the buyer's
 * receipt nor forwards it to the tax authority. What goes in it is the admin's
 * choice — see {@see Configuration::commentSource()} and the settings tab.
 *
 * Returns `null` (no comment) when the source is "off", or when the chosen
 * source has no value for this order (e.g. a gateway that hasn't set a
 * transaction id). No comment is a truthful state — the plugin never invents a
 * placeholder to fill the field.
 */
/**
 * Not declared `final` so the FiscalJob unit test can mock this collaborator
 * via Mockery — matching {@see ItemBuilder} / {@see PaymentMapper}. There's no
 * production extension point.
 */
class CommentBuilder
{
    /**
     * VCR caps the comment at 500 characters; stay inside that bound so a
     * payload is never rejected on length. Order references are far shorter, so
     * this only ever bites a pathologically long gateway transaction id.
     */
    private const MAX_LENGTH = 500;

    public function build(WC_Order $order, string $source): ?string
    {
        $comment = match ($source) {
            Configuration::COMMENT_SOURCE_ORDER_NUMBER => $this->orderReference($order),
            Configuration::COMMENT_SOURCE_TRANSACTION_ID => $this->transactionId($order),
            Configuration::COMMENT_SOURCE_ORDER_AND_TRANSACTION => $this->orderAndTransaction($order),
            // COMMENT_SOURCE_OFF, or any stray stored value, means "no comment".
            default => null,
        };

        if ($comment === null || $comment === '') {
            return null;
        }

        // VCR trims and strips control characters server-side; the plugin only
        // guards the length so the request can't be rejected for it.
        return mb_substr($comment, 0, self::MAX_LENGTH);
    }

    /**
     * `get_order_number()` respects sequential-order-number plugins and falls
     * back to the numeric id, so it's always a non-empty, human-recognisable
     * reference. Prefixed so it's self-describing in a VCR dashboard that also
     * carries desk and API sales.
     */
    private function orderReference(WC_Order $order): string
    {
        return sprintf('WooCommerce #%s', $order->get_order_number());
    }

    /**
     * The payment-gateway transaction id (e.g. a Stripe `pi_…`), returned raw
     * so it pastes cleanly into the gateway's own search. `null` when the
     * gateway hasn't recorded one.
     */
    private function transactionId(WC_Order $order): ?string
    {
        $transactionId = trim($order->get_transaction_id());

        return $transactionId === '' ? null : $transactionId;
    }

    private function orderAndTransaction(WC_Order $order): string
    {
        $reference = $this->orderReference($order);
        $transactionId = $this->transactionId($order);

        return $transactionId === null
            ? $reference
            : sprintf('%s (%s)', $reference, $transactionId);
    }
}
