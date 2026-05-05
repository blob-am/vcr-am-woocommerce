<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use WP_Query;

/**
 * "Filter by fiscal status" dropdown above the WooCommerce → Orders
 * table, plus the query modification that actually narrows the result
 * set when a status is selected. Mirrors WC's own "Filter by created
 * via" dropdown — same UI placement, same submit-via-Filter-button flow.
 *
 * Two paths again (HPOS + legacy) because the filter dropdowns hook
 * different actions and the query modifications go through different
 * APIs:
 *
 *   - HPOS: `woocommerce_order_list_table_restrict_manage_orders`
 *           action for the dropdown,
 *           `woocommerce_order_list_table_prepare_items_query_args`
 *           filter for the query (modifies args passed to wc_get_orders()).
 *   - Legacy: `restrict_manage_posts` for the dropdown (gated on the
 *           shop_order post type), `pre_get_posts` for the WP_Query
 *           modification.
 *
 * Special "not_enqueued" filter value catches orders that never got
 * a fiscal job kicked off (zero or empty `_vcr_fiscal_status` meta) —
 * that's the bucket admins want when triaging "what's outstanding".
 */
class OrdersListFilter
{
    /**
     * URL query param the dropdown submits. Prefixed with `_vcr_` so
     * we don't collide with WC's own `_status` etc. params, and the
     * underscore prefix marks it as plugin-internal (matches WC's
     * `_created_via` convention).
     */
    public const QUERY_PARAM = '_vcr_fiscal_status';

    /** Sentinel value for "never enqueued" (no meta key at all). */
    public const NOT_ENQUEUED = 'not_enqueued';

    public function register(): void
    {
        // HPOS list table.
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'renderDropdown']);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'filterHposQuery']);

        // Legacy post-type list table.
        add_action('restrict_manage_posts', [$this, 'renderLegacyDropdown']);
        add_action('pre_get_posts', [$this, 'filterLegacyQuery']);
    }

    /**
     * HPOS dropdown — fires inside the existing tablenav, no need to
     * gate on screen.
     */
    public function renderDropdown(): void
    {
        $this->printDropdown($this->currentSelection());
    }

    /**
     * Legacy dropdown — `restrict_manage_posts` fires for EVERY admin
     * list table (posts, pages, plugins). Gate on `shop_order` post
     * type so we don't pollute unrelated admin pages.
     */
    public function renderLegacyDropdown(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended — read-only screen routing
        $screen = isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order';
        if (! $screen) {
            return;
        }

        $this->printDropdown($this->currentSelection());
    }

    /**
     * HPOS query modification: rewrite `wc_get_orders()` args to inject
     * a meta_query for the selected fiscal status.
     *
     * @param  mixed $args
     * @return array<string, mixed>
     */
    public function filterHposQuery($args): array
    {
        if (! is_array($args)) {
            return [];
        }

        // Narrow keys to string for PHPStan — wc_get_orders args are
        // always string-keyed in practice, but PHP arrays are
        // structurally `array<int|string, mixed>`.
        /** @var array<string, mixed> $argsCleaned */
        $argsCleaned = [];
        foreach ($args as $key => $value) {
            $argsCleaned[(string) $key] = $value;
        }

        $selection = $this->currentSelection();
        if ($selection === '') {
            return $argsCleaned;
        }

        $existingMq = isset($argsCleaned['meta_query']) && is_array($argsCleaned['meta_query'])
            ? $argsCleaned['meta_query']
            : [];

        $argsCleaned['meta_query'] = $this->mergeMetaQuery($existingMq, $selection);

        return $argsCleaned;
    }

    /**
     * Legacy query modification via `pre_get_posts`. Gated on:
     *   - admin context only (don't accidentally affect frontend queries)
     *   - the main query (sub-queries from widgets/REST aren't ours)
     *   - the shop_order post type
     */
    public function filterLegacyQuery(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        $postType = $query->get('post_type');
        if ($postType !== 'shop_order') {
            return;
        }

        $selection = $this->currentSelection();
        if ($selection === '') {
            return;
        }

        $existing = $query->get('meta_query');
        $merged = $this->mergeMetaQuery(
            is_array($existing) ? $existing : [],
            $selection,
        );

        $query->set('meta_query', $merged);
    }

    /**
     * Read the current dropdown selection from $_GET, validating it
     * against the closed set of acceptable values. Anything else
     * (typo, malicious crafting) is treated as "no filter".
     */
    private function currentSelection(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = isset($_GET[self::QUERY_PARAM]) && is_string($_GET[self::QUERY_PARAM])
            ? sanitize_text_field(wp_unslash($_GET[self::QUERY_PARAM]))
            : '';

        if ($raw === '') {
            return '';
        }

        if ($raw === self::NOT_ENQUEUED) {
            return self::NOT_ENQUEUED;
        }

        // Validate against the FiscalStatus enum's known values.
        $matched = FiscalStatus::tryFrom($raw);

        return $matched !== null ? $matched->value : '';
    }

    /**
     * Append our fiscal-status meta condition to whatever meta_query
     * the underlying list table already has, so we don't trample the
     * WC built-in or other plugins' filters.
     *
     * @param  array<int|string, mixed> $existing
     * @return array<int|string, mixed>
     */
    private function mergeMetaQuery(array $existing, string $selection): array
    {
        if ($selection === self::NOT_ENQUEUED) {
            // "Never enqueued" = the meta key doesn't exist at all.
            // NOT_EXISTS is robust against WC's per-order meta storage
            // toggling between postmeta and HPOS columns.
            $clause = [
                'key' => FiscalStatusMeta::META_STATUS,
                'compare' => 'NOT EXISTS',
            ];
        } else {
            $clause = [
                'key' => FiscalStatusMeta::META_STATUS,
                'value' => $selection,
                'compare' => '=',
            ];
        }

        // If existing has a relation, append; otherwise wrap into AND.
        if ($existing === []) {
            return [$clause];
        }

        return [
            'relation' => 'AND',
            $existing,
            $clause,
        ];
    }

    /**
     * Output the actual <select> HTML — same shape WC uses for its own
     * filters (placed inside the .actions div before the Filter button).
     */
    private function printDropdown(string $current): void
    {
        $options = [
            '' => __('All fiscal statuses', 'vcr'),
            self::NOT_ENQUEUED => __('Not enqueued', 'vcr'),
            FiscalStatus::Pending->value => __('Queued', 'vcr'),
            FiscalStatus::Success->value => __('Registered', 'vcr'),
            FiscalStatus::Failed->value => __('Failed', 'vcr'),
            FiscalStatus::ManualRequired->value => __('Needs attention', 'vcr'),
        ];

        echo '<select name="' . esc_attr(self::QUERY_PARAM) . '" id="filter-by-vcr-fiscal-status">';
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($value, $current, false),
                esc_html($label),
            );
        }
        echo '</select>';
    }
}
