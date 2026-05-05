<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Adds a "Fiscal" column to the WooCommerce → Orders list table so
 * shop admins can scan many orders' fiscalisation state at once
 * without opening each. Inserted between "Status" and "Total" — that
 * spot puts it next to the (similar-looking) order status column,
 * which is where admins look anyway.
 *
 * Two registration paths because WC has two list-table backends:
 *
 *   - **HPOS** (custom orders table, default since WC 8.2): hook
 *     `woocommerce_shop_order_list_table_columns` for the header,
 *     `woocommerce_shop_order_list_table_custom_column` for cells.
 *   - **Legacy** (`shop_order` CPT): the WP-classic
 *     `manage_edit-shop_order_columns` + `manage_shop_order_posts_custom_column`.
 *
 * Both are wired unconditionally — at runtime exactly one of the two
 * list tables renders, so the unused hook is just inert. Cheaper than
 * detecting which backend is active (OrderUtil::custom_orders_table_usage_is_enabled())
 * and gating, and it future-proofs against admins toggling HPOS off.
 */
class OrdersListColumn
{
    public const COLUMN_KEY = 'vcr_fiscal_status';

    public function __construct(
        private readonly FiscalStatusMeta $meta,
    ) {
    }

    public function register(): void
    {
        // HPOS list table.
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'addColumn']);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'renderHposCell'], 10, 2);

        // Legacy (post-type) list table.
        add_filter('manage_edit-shop_order_columns', [$this, 'addColumn']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderLegacyCell'], 10, 2);
    }

    /**
     * Inject the column header BETWEEN "order_status" and "order_total"
     * if those keys exist (HPOS naming) or "order_status" / "order_total"
     * legacy naming. If neither is present, append at the end —
     * that's the safe fallback when other plugins have rearranged
     * columns.
     *
     * @param  mixed $columns
     * @return array<string, string>
     */
    public function addColumn($columns): array
    {
        if (! is_array($columns)) {
            return [self::COLUMN_KEY => esc_html__('Fiscal', 'vcr-am-fiscal-receipts')];
        }

        $injected = [];
        $inserted = false;

        foreach ($columns as $key => $label) {
            $stringKey = is_string($key) ? $key : (string) $key;
            $stringLabel = is_string($label) ? $label : '';
            $injected[$stringKey] = $stringLabel;

            // Insert immediately after the WC status column.
            if (! $inserted && ($stringKey === 'order_status' || $stringKey === 'status')) {
                $injected[self::COLUMN_KEY] = esc_html__('Fiscal', 'vcr-am-fiscal-receipts');
                $inserted = true;
            }
        }

        if (! $inserted) {
            $injected[self::COLUMN_KEY] = esc_html__('Fiscal', 'vcr-am-fiscal-receipts');
        }

        return $injected;
    }

    /**
     * HPOS callback signature: ($column_name, $order).
     *
     * @param  mixed $columnName
     * @param  mixed $order
     */
    public function renderHposCell($columnName, $order): void
    {
        if ($columnName !== self::COLUMN_KEY || ! $order instanceof WC_Order) {
            return;
        }

        $this->renderBadge($order);
    }

    /**
     * Legacy callback signature: ($column_name, $post_id). Resolve the
     * order from the post id and reuse the same badge renderer.
     *
     * @param  mixed $columnName
     * @param  mixed $postId
     */
    public function renderLegacyCell($columnName, $postId): void
    {
        if ($columnName !== self::COLUMN_KEY) {
            return;
        }

        // WP core passes int $post_id through `manage_<post_type>_posts_custom_column`,
        // but admin extensions like Admin Columns Pro / Smart Manager
        // re-fire the action with stringly-typed ids harvested from
        // $_REQUEST. Coerce so we don't silently drop their cells.
        $id = is_int($postId)
            ? $postId
            : (is_string($postId) && ctype_digit($postId) ? (int) $postId : 0);
        if ($id <= 0) {
            return;
        }

        $order = wc_get_order($id);
        if (! $order instanceof WC_Order) {
            return;
        }

        $this->renderBadge($order);
    }

    /**
     * Renders a colour-coded chip mirroring WC's order-status badge
     * style (`mark` element with a status-* class). Reuses WC's CSS
     * so the chip blends visually with the native Status column right
     * next to it.
     */
    private function renderBadge(WC_Order $order): void
    {
        $status = $this->meta->status($order);

        if ($status === null) {
            // Not enqueued yet — render a low-contrast placeholder so
            // the column doesn't have an empty cell (which reads as
            // "data missing" rather than "no fiscal action").
            // Em dash is purely decorative; not a translatable string.
            echo '<mark class="order-status" style="background:#e0e0e0;color:#555">'
                . '<span>—</span></mark>';

            return;
        }

        [$label, $color, $background] = self::badgeColors($status);

        printf(
            '<mark class="order-status" style="background:%s;color:%s"><span>%s</span></mark>',
            esc_attr($background),
            esc_attr($color),
            esc_html($label),
        );
    }

    /**
     * Map status → [label, fg, bg]. Colors mirror WC's own status
     * conventions (yellow=processing-like, green=completed,
     * red=failed) so admins read them by analogy without needing to
     * learn a new colour vocabulary.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function badgeColors(FiscalStatus $status): array
    {
        return match ($status) {
            FiscalStatus::Pending => [__('Queued', 'vcr-am-fiscal-receipts'), '#94660c', '#f8dda7'],
            FiscalStatus::Success => [__('Registered', 'vcr-am-fiscal-receipts'), '#5b841b', '#c8d7e1'],
            FiscalStatus::Failed => [__('Failed', 'vcr-am-fiscal-receipts'), '#761919', '#eba3a3'],
            FiscalStatus::ManualRequired => [__('Manual', 'vcr-am-fiscal-receipts'), '#761919', '#fbcbcb'],
        };
    }
}
