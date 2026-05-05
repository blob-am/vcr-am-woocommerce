<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Admin\OrderMetaBox;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use WC_Order;
use WC_Order_Refund;

/**
 * `admin-post.php` handler behind the meta box's "Register refund now"
 * button. Mirrors {@see \BlobSolutions\WooCommerceVcrAm\Admin\FiscalizeNowHandler}
 * for refunds: same auth model, same per-id nonce, same notice query
 * param scheme — admins see one consistent retry UX whether they're
 * retrying a sale or a refund.
 *
 * State guard: only Failed and ManualRequired refunds can be re-queued.
 * Success refunds are skipped (already filed); Pending refunds belong
 * to the queue.
 *
 * Redirect target: the *parent order* edit screen (where the meta box
 * lives), not the refund itself — refunds don't have their own admin
 * screen.
 */
class RefundFiscalizeNowHandler
{
    public const ACTION = 'vcr_register_refund_now';

    public const NONCE_ACTION = 'vcr-register-refund-now';

    public function __construct(
        private readonly RefundStatusMeta $meta,
        private readonly RefundQueue $queue,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        // Order: nonce -> capability -> work. Mirrors FiscalizeNowHandler;
        // see that handler's comment for the rationale.
        $refundIdRaw = isset($_POST['refund_id']) ? wp_unslash($_POST['refund_id']) : '';
        $refundId = is_string($refundIdRaw) && $refundIdRaw !== '' && ctype_digit($refundIdRaw)
            ? (int) $refundIdRaw
            : 0;

        // Pin the nonce to the refund id, same pattern as the sale handler.
        check_admin_referer(self::NONCE_ACTION . '_' . $refundId);

        if (! current_user_can('edit_shop_orders')) {
            wp_die(
                esc_html(__('You do not have permission to retry refund registration.', 'vcr')),
                '',
                ['response' => 403, 'back_link' => true],
            );
        }

        if ($refundId <= 0) {
            $this->redirectWithNotice(null, 'refund_invalid');
        }

        $refund = wc_get_order($refundId);
        if (! $refund instanceof WC_Order_Refund) {
            $this->redirectWithNotice(null, 'refund_invalid');
        }

        $status = $this->meta->status($refund);

        if ($status === FiscalStatus::Success) {
            $this->redirectWithNotice($refund, 'refund_skipped_success');
        }

        if ($status !== FiscalStatus::Failed && $status !== FiscalStatus::ManualRequired) {
            $this->redirectWithNotice($refund, 'refund_skipped_state');
        }

        $this->meta->resetForRetry($refund);
        $this->queue->enqueue($refundId);

        $this->redirectWithNotice($refund, 'refund_enqueued');
    }

    /**
     * Redirect to the parent order's edit screen — refunds don't have
     * their own admin URL. `null` refund means we couldn't resolve one;
     * fall back to the orders list.
     */
    private function redirectWithNotice(?WC_Order_Refund $refund, string $notice): never
    {
        $base = admin_url('admin.php?page=wc-orders');

        if ($refund instanceof WC_Order_Refund) {
            $parent = wc_get_order($refund->get_parent_id());
            if ($parent instanceof WC_Order) {
                $base = $parent->get_edit_order_url();
            }
        }

        $url = add_query_arg(OrderMetaBox::NOTICE_QUERY_PARAM, $notice, $base);

        wp_safe_redirect($url);
        exit;
    }
}
