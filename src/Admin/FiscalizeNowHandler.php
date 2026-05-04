<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use WC_Order;

/**
 * `admin-post.php` handler behind the meta box's "Fiscalize now" button.
 *
 * Synchronous form-POST flow (rather than AJAX) to match how the rest of
 * the WC admin handles single-order actions: the page fully reloads,
 * inline notices show up via the {@see OrderMetaBox::NOTICE_QUERY_PARAM}
 * query string. Simpler than an XHR + dynamic re-render pair, and the
 * action latency is dominated by the (offline) Action Scheduler enqueue,
 * not the HTTP round-trip.
 *
 * Authorisation:
 *   - Requires `edit_shop_orders` capability — the same gate WC uses for
 *     order-list mutations. `manage_woocommerce` would be too broad
 *     (gives shop-manager-only stores no extra protection); narrower
 *     would lock out staff who need to recover failed receipts.
 *   - Per-order nonce ({@see self::NONCE_ACTION} + order id) so a stolen
 *     nonce can't be replayed across orders.
 *
 * State guard: only Failed and ManualRequired orders can be re-queued.
 * Success orders are skipped (the receipt is already filed); Pending
 * orders are left alone (the queue still owns them).
 */
class FiscalizeNowHandler
{
    public const ACTION = 'vcr_fiscalize_now';

    public const NONCE_ACTION = 'vcr-fiscalize-now';

    public function __construct(
        private readonly FiscalStatusMeta $meta,
        private readonly FiscalQueue $queue,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(
                esc_html(__('You do not have permission to retry fiscalisation for this order.', 'vcr')),
                '',
                ['response' => 403, 'back_link' => true],
            );
        }

        $orderId = isset($_POST['order_id']) && is_string($_POST['order_id']) && ctype_digit($_POST['order_id'])
            ? (int) $_POST['order_id']
            : 0;

        // Pin the nonce to the order id so a leaked nonce can't be
        // replayed against an unrelated order.
        check_admin_referer(self::NONCE_ACTION . '_' . $orderId);

        if ($orderId <= 0) {
            $this->redirectWithNotice(null, 'fiscalize_invalid_order');
        }

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            $this->redirectWithNotice(null, 'fiscalize_invalid_order');
        }

        $status = $this->meta->status($order);

        if ($status === FiscalStatus::Success) {
            $this->redirectWithNotice($order, 'fiscalize_skipped_success');
        }

        // Only terminal-failure states can be retried. null (never
        // queued) and Pending (already in flight) are not appropriate
        // entry points for the manual retry button.
        if ($status !== FiscalStatus::Failed && $status !== FiscalStatus::ManualRequired) {
            $this->redirectWithNotice($order, 'fiscalize_skipped_state');
        }

        $this->meta->resetForRetry($order);
        $this->queue->enqueue($orderId);

        $this->redirectWithNotice($order, 'fiscalize_enqueued');
    }

    /**
     * Redirect back to the order edit screen with a notice marker.
     * Centralised so all branches share the same redirect logic.
     *
     * `null` order means we couldn't resolve one — fall back to the
     * orders list rather than a broken edit URL.
     */
    private function redirectWithNotice(?WC_Order $order, string $notice): never
    {
        $base = $order instanceof WC_Order
            ? $order->get_edit_order_url()
            : admin_url('admin.php?page=wc-orders');

        $url = add_query_arg(OrderMetaBox::NOTICE_QUERY_PARAM, $notice, $base);

        wp_safe_redirect($url);
        exit;
    }
}
