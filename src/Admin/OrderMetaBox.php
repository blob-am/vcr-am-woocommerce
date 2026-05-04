<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use WC_Order;
use WP_Post;

/**
 * The "VCR Fiscal Receipt" sidebar meta box on the WooCommerce order
 * edit screen. Read-only summary of fiscalisation state plus a
 * "Fiscalize now" button for terminal-failure states (Failed and
 * ManualRequired).
 *
 * HPOS-aware: registers against both the new `woocommerce_page_wc-orders`
 * screen (HPOS custom-table edit) and the legacy `shop_order` post type.
 * The render callback receives a WC_Order on HPOS and a WP_Post on the
 * legacy screen, both branches are handled via the {@see self::resolveOrder()}
 * helper.
 *
 * Status-driven render — each FiscalStatus has its own renderer so the
 * UX can speak plainly to that state ("queued for next attempt" vs
 * "registered with SRC" vs "needs admin attention") without a single
 * generic template trying to be everything at once.
 */
class OrderMetaBox
{
    public const META_BOX_ID = 'vcr-fiscal-status';

    /**
     * Query-string param appended after a redirect from the Fiscalize-now
     * handler. Read by {@see self::renderNotice()} to surface success or
     * failure feedback inline in the meta box.
     */
    public const NOTICE_QUERY_PARAM = 'vcr_notice';

    public function __construct(
        private readonly FiscalStatusMeta $meta,
    ) {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
    }

    public function addMetaBox(): void
    {
        add_meta_box(
            self::META_BOX_ID,
            __('VCR Fiscal Receipt', 'vcr'),
            [$this, 'render'],
            // Both screens — HPOS new + legacy post type. WC core does
            // the equivalent dual-registration for its own boxes.
            ['woocommerce_page_wc-orders', 'shop_order'],
            'side',
            'default',
        );
    }

    /**
     * @param  WC_Order|WP_Post|mixed $postOrOrder
     */
    public function render($postOrOrder): void
    {
        $order = $this->resolveOrder($postOrOrder);

        if (! $order instanceof WC_Order) {
            echo '<p>' . esc_html(__('Order not available.', 'vcr')) . '</p>';

            return;
        }

        $this->renderNotice();

        $status = $this->meta->status($order);

        if ($status === null) {
            $this->renderNotEnqueued();

            return;
        }

        match ($status) {
            FiscalStatus::Pending => $this->renderPending($order),
            FiscalStatus::Success => $this->renderSuccess($order),
            FiscalStatus::Failed => $this->renderFailed($order),
            FiscalStatus::ManualRequired => $this->renderManualRequired($order),
        };
    }

    /**
     * @param  WC_Order|WP_Post|mixed $postOrOrder
     */
    private function resolveOrder($postOrOrder): ?WC_Order
    {
        if ($postOrOrder instanceof WC_Order) {
            return $postOrOrder;
        }

        if ($postOrOrder instanceof WP_Post) {
            $resolved = wc_get_order($postOrOrder->ID);

            return $resolved instanceof WC_Order ? $resolved : null;
        }

        return null;
    }

    private function renderNotEnqueued(): void
    {
        echo '<p>' . esc_html(__(
            'Not yet fiscalised. The plugin will queue this order automatically once payment completes.',
            'vcr',
        )) . '</p>';
    }

    private function renderPending(WC_Order $order): void
    {
        $attempt = $this->meta->attemptCount($order);
        $lastError = $this->meta->lastError($order);

        echo '<p><strong>' . esc_html(__('Status:', 'vcr')) . '</strong> '
            . esc_html(__('Queued for fiscalisation', 'vcr')) . '</p>';

        if ($attempt > 0) {
            printf(
                '<p>%s %d</p>',
                esc_html(__('Attempts so far:', 'vcr')),
                $attempt,
            );
        }

        if ($lastError !== null) {
            $this->renderErrorBlock($lastError);
        }
    }

    private function renderSuccess(WC_Order $order): void
    {
        echo '<p><strong>' . esc_html(__('Status:', 'vcr')) . '</strong> '
            . esc_html(__('Registered with SRC', 'vcr')) . '</p>';

        $this->renderKeyValueTable([
            __('Fiscal serial', 'vcr') => $this->meta->fiscal($order),
            __('CRN', 'vcr') => $this->meta->crn($order),
            __('Receipt id', 'vcr') => $this->meta->urlId($order),
        ]);
    }

    private function renderFailed(WC_Order $order): void
    {
        echo '<p><strong>' . esc_html(__('Status:', 'vcr')) . '</strong> '
            . esc_html(__('Failed (retries exhausted)', 'vcr')) . '</p>';

        $lastError = $this->meta->lastError($order);
        if ($lastError !== null) {
            $this->renderErrorBlock($lastError);
        }

        $this->renderFiscalizeNowForm($order);
    }

    private function renderManualRequired(WC_Order $order): void
    {
        echo '<p><strong>' . esc_html(__('Status:', 'vcr')) . '</strong> '
            . esc_html(__('Needs your attention', 'vcr')) . '</p>';

        $lastError = $this->meta->lastError($order);
        if ($lastError !== null) {
            $this->renderErrorBlock($lastError);
        }

        $this->renderFiscalizeNowForm($order);
    }

    private function renderFiscalizeNowForm(WC_Order $order): void
    {
        $orderId = $order->get_id();
        $action = FiscalizeNowHandler::ACTION;
        $nonceAction = FiscalizeNowHandler::NONCE_ACTION . '_' . $orderId;

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:1em">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $orderId) . '">';
        wp_nonce_field($nonceAction);
        echo '<button type="submit" class="button button-primary">'
            . esc_html(__('Fiscalize now', 'vcr')) . '</button>';
        echo '</form>';
    }

    private function renderErrorBlock(string $message): void
    {
        echo '<p><strong>' . esc_html(__('Last error:', 'vcr')) . '</strong></p>';
        echo '<p style="color:#b32d2e">' . esc_html($message) . '</p>';
    }

    /**
     * @param array<string, ?string> $rows
     */
    private function renderKeyValueTable(array $rows): void
    {
        echo '<table class="widefat striped" style="margin-top:0.5em"><tbody>';

        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            echo '<tr>';
            echo '<th scope="row" style="width:40%">' . esc_html($label) . '</th>';
            echo '<td><code>' . esc_html($value) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function renderNotice(): void
    {
        if (! isset($_GET[self::NOTICE_QUERY_PARAM])) {
            return;
        }

        $rawNotice = $_GET[self::NOTICE_QUERY_PARAM];
        if (! is_string($rawNotice)) {
            return;
        }

        $notice = sanitize_key($rawNotice);

        $message = match ($notice) {
            'fiscalize_enqueued' => __('Fiscalisation re-queued. The next attempt will run shortly.', 'vcr'),
            'fiscalize_skipped_success' => __('Order is already fiscalised — nothing to retry.', 'vcr'),
            'fiscalize_skipped_state' => __('Order is not in a state that can be retried.', 'vcr'),
            'fiscalize_invalid_order' => __('The selected order could not be found.', 'vcr'),
            default => null,
        };

        if ($message === null) {
            return;
        }

        $cssClass = $notice === 'fiscalize_enqueued' ? 'notice-success' : 'notice-warning';

        printf(
            '<div class="notice %s inline" style="padding:0.5em;margin:0 0 1em"><p>%s</p></div>',
            esc_attr($cssClass),
            esc_html($message),
        );
    }
}
