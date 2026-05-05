<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Receipt;

use WC_Order;

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
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_email_order_meta', [$this, 'renderInEmail'], 10, 4);
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

        $url = $this->urlBuilder->build($order);
        if ($url === null) {
            return;
        }

        if ($plainText) {
            // WC's plain-text emails are mostly bare \n-separated lines —
            // mimic that style so we don't disrupt the visual flow.
            echo "\n" . __('View your fiscal receipt:', 'vcr') . ' ' . $url . "\n";

            return;
        }

        printf(
            '<p style="margin-top:1em"><strong>%s</strong> <a href="%s">%s</a></p>',
            esc_html(__('Fiscal receipt:', 'vcr')),
            esc_url($url),
            esc_html(__('View your receipt', 'vcr')),
        );
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
        if ($url === null) {
            return;
        }

        printf(
            '<p class="vcr-receipt-link"><strong>%s</strong> <a href="%s">%s</a></p>',
            esc_html(__('Fiscal receipt:', 'vcr')),
            esc_url($url),
            esc_html(__('View your receipt', 'vcr')),
        );
    }
}
