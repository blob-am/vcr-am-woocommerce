<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Adds a "Retry VCR fiscalisation" entry to the WC → Orders bulk-action
 * dropdown. Lets shop admins recover N orders from `Failed` /
 * `ManualRequired` in one click after fixing config (e.g., an admin
 * paste-fixed the API key and now wants to re-fire the queue against
 * everything that piled up).
 *
 * Two registrations because WC has two list-table screens — same
 * dual-registration pattern as {@see OrdersListColumn} and
 * {@see OrdersListFilter}.
 *
 * Per-order safety: each order goes through the same eligibility check
 * as the per-order "Fiscalize now" button — only Failed / ManualRequired
 * are re-queued. Already-Success orders are silently skipped (no double
 * registration). Pending orders are left alone (queue still owns them).
 *
 * Result reporting: the WC bulk-action handler convention is to append
 * a query param to the redirect URL for a notice. We append two:
 * `vcr_bulk_queued=N` (success count) and `vcr_bulk_skipped=N` (already
 * Success or wrong state). The admin sees an inline notice on return.
 */
class OrdersBulkAction
{
    /**
     * Bulk-action key. The `vcr_` prefix makes it obvious in the
     * dropdown which plugin owns it (matches WC's own convention of
     * prefixing extension actions).
     */
    public const ACTION = 'vcr_retry_fiscalisation';

    /** Cap on how many orders one bulk action can re-queue at a time. */
    private const MAX_BULK = 100;

    public function __construct(
        private readonly FiscalStatusMeta $meta,
        private readonly FiscalQueue $queue,
    ) {
    }

    public function register(): void
    {
        // HPOS list table.
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'defineActions']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handle'], 10, 3);

        // Legacy post-type list table.
        add_filter('bulk_actions-edit-shop_order', [$this, 'defineActions']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle'], 10, 3);

        // Render the post-redirect result notice on both list tables.
        add_action('admin_notices', [$this, 'renderResultNotice']);
    }

    /**
     * Render the inline notice produced by `handle()` after the bulk
     * redirect. Reads the two query params we appended (queued count +
     * skipped count) and emits one combined message.
     *
     * Gated permissively — bad/missing query params just render
     * nothing rather than throwing, since admin_notices fires on every
     * admin page including ones unrelated to our bulk action.
     */
    public function renderResultNotice(): void
    {
        // Scope to the orders screens — without this gate, an attacker
        // can craft `wp-admin/index.php?vcr_bulk_queued=999` and our
        // notice renders on every admin page that opens. Cosmetic
        // spoofing rather than an exploit, but worth closing.
        if (! $this->isOrdersScreen()) {
            return;
        }

        if (! isset($_GET['vcr_bulk_queued']) && ! isset($_GET['vcr_bulk_skipped'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended — read-only render
        $rawQueued = isset($_GET['vcr_bulk_queued']) ? wp_unslash($_GET['vcr_bulk_queued']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rawSkipped = isset($_GET['vcr_bulk_skipped']) ? wp_unslash($_GET['vcr_bulk_skipped']) : 0;
        $queued = is_numeric($rawQueued) ? (int) $rawQueued : 0;
        $skipped = is_numeric($rawSkipped) ? (int) $rawSkipped : 0;

        if ($queued <= 0 && $skipped <= 0) {
            return;
        }

        $parts = [];
        if ($queued > 0) {
            $parts[] = sprintf(
                /* translators: %d: number of orders re-queued for fiscalisation */
                _n(
                    '%d order re-queued for VCR fiscalisation.',
                    '%d orders re-queued for VCR fiscalisation.',
                    $queued,
                    'vcr-am-fiscal-receipts',
                ),
                $queued,
            );
        }
        if ($skipped > 0) {
            $parts[] = sprintf(
                /* translators: %d: number of orders skipped (not in a retriable state) */
                _n(
                    '%d order skipped (not in a retriable state).',
                    '%d orders skipped (not in a retriable state).',
                    $skipped,
                    'vcr-am-fiscal-receipts',
                ),
                $skipped,
            );
        }

        $cssClass = $queued > 0 ? 'notice-success' : 'notice-warning';

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($cssClass),
            esc_html(implode(' ', $parts)),
        );
    }

    /**
     * Detect WC orders screen — both legacy CPT and HPOS variants. Used
     * to gate the post-bulk-action notice render so a crafted URL
     * spraying our query params anywhere in wp-admin can't conjure
     * a fake "999 orders re-queued" notice.
     */
    private function isOrdersScreen(): bool
    {
        if (! function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if ($screen === null) {
            return false;
        }

        // HPOS screen id, legacy CPT screen id.
        return in_array($screen->id, ['woocommerce_page_wc-orders', 'edit-shop_order'], true);
    }

    /**
     * @param  mixed $actions
     * @return array<string, string>
     */
    public function defineActions($actions): array
    {
        $actions = is_array($actions) ? $actions : [];

        // Filter to string-keyed string-valued only — the bulk dropdown
        // requires that shape.
        $cleaned = [];
        foreach ($actions as $key => $label) {
            if (is_string($key) && is_string($label)) {
                $cleaned[$key] = $label;
            }
        }

        $cleaned[self::ACTION] = __('Retry VCR fiscalisation', 'vcr-am-fiscal-receipts');

        return $cleaned;
    }

    /**
     * Bulk-action handler. WC core calls every registered handler;
     * each is responsible for matching its own action and returning
     * the redirect URL unchanged when it doesn't apply.
     *
     * @param  mixed $redirectTo
     * @param  mixed $action
     * @param  mixed $ids
     */
    public function handle($redirectTo, $action, $ids): string
    {
        $redirect = is_string($redirectTo) ? $redirectTo : '';

        if ($action !== self::ACTION) {
            return $redirect;
        }

        if (! current_user_can('edit_shop_orders')) {
            // WC bulk-action UI already gates by capability, but if a
            // crafted POST gets here we refuse silently rather than
            // wp_die-ing (the rest of the bulk action might still be
            // valid for other handlers).
            return $redirect;
        }

        if (! is_array($ids)) {
            return $redirect;
        }

        $queued = 0;
        $skipped = 0;
        $processed = 0;

        foreach ($ids as $id) {
            // MAX_BULK cap protects against accidentally enqueuing
            // thousands of jobs in one click — admins targeting a
            // larger batch should use WP-CLI.
            if ($processed >= self::MAX_BULK) {
                break;
            }
            $processed++;

            $orderId = 0;
            if (is_int($id)) {
                $orderId = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $orderId = (int) $id;
            } else {
                $skipped++;

                continue;
            }
            $order = wc_get_order($orderId);

            if (! $order instanceof WC_Order || $order->get_type() !== 'shop_order') {
                $skipped++;

                continue;
            }

            $status = $this->meta->status($order);

            // Only Failed / ManualRequired are eligible — same gate as
            // the per-order "Fiscalize now" button. Success orders are
            // already done; Pending are still in flight.
            if ($status !== FiscalStatus::Failed && $status !== FiscalStatus::ManualRequired) {
                $skipped++;

                continue;
            }

            $this->meta->resetForRetry($order);
            $this->queue->enqueue($orderId);
            $queued++;
        }

        return add_query_arg(
            [
                'vcr_bulk_queued' => $queued,
                'vcr_bulk_skipped' => $skipped,
            ],
            $redirect,
        );
    }
}
