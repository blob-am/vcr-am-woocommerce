<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Receipt;

use BlobSolutions\WooCommerceVcrAm\Refund\RefundReceiptUrlBuilder;
use WC_Order;
use WC_Order_Refund;

/**
 * Customer-facing surfaces that show a "View your fiscal receipt" link
 * once an order has been successfully fiscalised with SRC via VCR.
 *
 * Three placement points cover the standard WC customer journey:
 *
 *   - Transactional emails (`woocommerce_email_order_meta`) — the
 *     "Your order is processing" mail customers actually open.
 *   - Order received / thank-you page (`woocommerce_thankyou`) — the
 *     post-checkout confirmation screen.
 *   - My Account → Orders → View order
 *     (`woocommerce_order_details_after_order_table`).
 *
 * Render is gated on {@see ReceiptUrlBuilder::build()} returning a
 * non-null URL — i.e. status === Success and we have both `crn` and
 * `urlId`. For any other state we render nothing rather than a "your
 * receipt is being generated" placeholder; the customer doesn't need
 * to know about the back-office fiscalisation lifecycle, only the
 * outcome.
 *
 * Admin-side notification emails (`$sent_to_admin === true`) are
 * skipped — store owners use the order edit screen meta box, the
 * receipt URL would just be noise in their inbox.
 */
class CustomerReceiptDisplay
{
    public function __construct(
        private readonly ReceiptUrlBuilder $urlBuilder,
        private readonly RefundReceiptUrlBuilder $refundUrlBuilder,
    ) {
    }

    public function register(): void
    {
        // `woocommerce_email_after_order_table` is the canonical hook
        // for "extra info just below the order table in transactional
        // emails" — used by Stripe, Subscriptions, Memberships, etc.
        // The earlier `woocommerce_email_order_meta` hook fires inside
        // the meta-block region, which Kadence Email Designer / MailPoet
        // / many email customizer plugins suppress or restyle, leaving
        // the receipt link in surprising positions. Keep the signature
        // identical — both hooks pass `(WC_Order, sent_to_admin, plain_text, WC_Email)`.
        add_action('woocommerce_email_after_order_table', [$this, 'renderInEmail'], 10, 4);
        add_action('woocommerce_thankyou', [$this, 'renderOnThankYou'], 20);
        add_action('woocommerce_order_details_after_order_table', [$this, 'renderInOrderDetails']);
    }

    /**
     * @param  WC_Order|mixed $order
     * @param  mixed         $email   WC_Email instance, unused — present so
     *                                we honour the action's full signature
     */
    public function renderInEmail($order, bool $sentToAdmin = false, bool $plainText = false, $email = null): void
    {
        if ($sentToAdmin || ! $order instanceof WC_Order) {
            return;
        }

        // Sale receipt link (when order is registered with SRC).
        $url = $this->urlBuilder->build($order);
        if ($url !== null) {
            if ($plainText) {
                echo "\n" . __('View your fiscal receipt:', 'vcr') . ' ' . $url . "\n";
            } else {
                printf(
                    '<p style="margin-top:1em"><strong>%s</strong> <a href="%s" rel="noopener noreferrer">%s</a></p>',
                    esc_html(__('Fiscal receipt:', 'vcr')),
                    esc_url($url),
                    esc_html(__('View your receipt', 'vcr')),
                );
            }
        }

        // Refund receipt links (when refunds exist and are registered).
        // We render ALL successfully-registered refunds, not just one,
        // so a customer who got two partial refunds sees both links.
        // The `customer-refunded-order` email naturally surfaces these
        // alongside the original receipt link above; the `customer-
        // processing-order` email won't have any refunds yet so this
        // section will simply render nothing.
        $this->renderRefundReceiptLinks($order, $plainText);
    }

    /**
     * @param int|mixed $orderId
     */
    public function renderOnThankYou($orderId): void
    {
        if (! is_int($orderId) && ! (is_string($orderId) && ctype_digit($orderId))) {
            return;
        }

        $order = wc_get_order((int) $orderId);
        if (! $order instanceof WC_Order) {
            return;
        }

        $url = $this->urlBuilder->build($order);
        if ($url === null) {
            return;
        }

        printf(
            '<section class="vcr-receipt-callout woocommerce-order-details" style="margin-top:1.5em">' .
            '<h2>%s</h2><p><a class="button" href="%s">%s</a></p></section>',
            esc_html(__('Fiscal receipt', 'vcr')),
            esc_url($url),
            esc_html(__('View your receipt', 'vcr')),
        );
    }

    /**
     * @param  WC_Order|mixed $order
     */
    public function renderInOrderDetails($order): void
    {
        if (! $order instanceof WC_Order) {
            return;
        }

        $url = $this->urlBuilder->build($order);
        if ($url !== null) {
            printf(
                '<p class="vcr-receipt-link"><strong>%s</strong> <a href="%s" rel="noopener noreferrer">%s</a></p>',
                esc_html(__('Fiscal receipt:', 'vcr')),
                esc_url($url),
                esc_html(__('View your receipt', 'vcr')),
            );
        }

        $this->renderRefundReceiptLinks($order, plainText: false);
    }

    /**
     * Iterate the order's refunds, render a receipt link for each one
     * that has been successfully registered with SRC. Used in both the
     * email and order-details surfaces (the thank-you page is checkout-
     * time only, no refunds exist yet).
     */
    private function renderRefundReceiptLinks(WC_Order $order, bool $plainText): void
    {
        foreach ($order->get_refunds() as $refund) {
            if (! $refund instanceof WC_Order_Refund) {
                continue;
            }

            $refundUrl = $this->refundUrlBuilder->build($refund);
            if ($refundUrl === null) {
                continue;
            }

            if ($plainText) {
                echo "\n" . sprintf(
                    /* translators: 1: refund id */
                    __('View your fiscal refund receipt (refund #%d):', 'vcr'),
                    $refund->get_id(),
                ) . ' ' . $refundUrl . "\n";

                continue;
            }

            printf(
                '<p class="vcr-refund-receipt-link" style="margin-top:0.5em">' .
                '<strong>%s</strong> <a href="%s" rel="noopener noreferrer">%s</a></p>',
                esc_html(sprintf(
                    /* translators: 1: refund id */
                    __('Refund receipt (#%d):', 'vcr'),
                    $refund->get_id(),
                )),
                esc_url($refundUrl),
                esc_html(__('View refund receipt', 'vcr')),
            );
        }
    }
}
