<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundQueue;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Renders a "VCR — Fiscal Receipts" section on the WooCommerce → Status
 * → System Status report page. Standard WC convention for plugins to
 * surface their config + health info so support staff can debug
 * without admin-screen access — when a customer reports a problem they
 * paste the System Status report and we (or any support agent) can see
 * the plugin state at a glance.
 *
 * What we surface (and what we deliberately don't):
 *
 *   - **Show:** plugin version, has-API-key (boolean), base URL,
 *     configured cashier/department/shipping-SKU/fee-SKU presence,
 *     test mode, AS pending-action counts per queue, fiscal status
 *     counts (Pending/Failed/ManualRequired) — anything that would
 *     answer "is the plugin set up and operating?"
 *
 *   - **Never show:** the API key itself, the encrypted ciphertext,
 *     the salt-derived encryption key, customer order ids in the
 *     "Failed" / "ManualRequired" buckets. The report is shareable
 *     in support tickets — it must contain zero PII or secrets.
 *
 * Counts are computed by a direct `SELECT meta_value, COUNT(*)` against
 * the order-meta table. We resolve the table name at query time:
 *
 *   - **HPOS authoritative** (the WC default since 8.2 and a hard
 *     requirement of any new Woo Marketplace submission): order meta
 *     lives in `{$wpdb->prefix}wc_orders_meta`. Reading from `wp_postmeta`
 *     would silently return zero rows on stores that have completed the
 *     HPOS migration with sync turned off — exactly the surface support
 *     staff paste into tickets, where a misreport is worst.
 *   - **Legacy posts authoritative** (older stores, or those that
 *     explicitly disabled HPOS): meta lives in `{$wpdb->postmeta}`.
 *
 * The detection uses `OrderUtil::custom_orders_table_usage_is_enabled()`
 * which is the documented WC API (introduced in WC 7.1) and the same
 * thing WC core uses internally to decide where to read order meta.
 * Wrapped in `class_exists` so this file is safe to evaluate before WC
 * has bootstrapped (System Status renders late in the request, after WC
 * is fully loaded — but the guard is cheap and removes a class of "what
 * if WC was deactivated mid-request" weirdness).
 */
class SystemStatusReport
{
    public function __construct(
        private readonly string $pluginVersion,
        private readonly Configuration $config,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_system_status_report', [$this, 'render']);
    }

    public function render(): void
    {
        $rows = $this->collectRows();

        echo '<table class="wc_status_table widefat" cellspacing="0">';
        echo '<thead><tr><th colspan="3" data-export-label="VCR Fiscal Receipts">'
            . '<h2>' . esc_html__('VCR — Fiscal Receipts (Armenia)', 'vcr-am-fiscal-receipts') . '</h2>'
            . '</th></tr></thead><tbody>';

        foreach ($rows as $label => $value) {
            printf(
                '<tr><td data-export-label="%s">%s:</td><td class="help">&nbsp;</td><td>%s</td></tr>',
                esc_attr($label),
                esc_html($label),
                esc_html($value),
            );
        }

        echo '</tbody></table>';
    }

    /**
     * @return array<string, string>
     */
    private function collectRows(): array
    {
        $rows = [
            __('Plugin version', 'vcr-am-fiscal-receipts') => $this->pluginVersion,
            __('API key configured', 'vcr-am-fiscal-receipts') => $this->config->hasCredentials() ? __('Yes', 'vcr-am-fiscal-receipts') : __('No', 'vcr-am-fiscal-receipts'),
            __('Base URL', 'vcr-am-fiscal-receipts') => $this->stripCredentials($this->config->baseUrl()),
            __('Test mode', 'vcr-am-fiscal-receipts') => $this->config->isTestMode() ? __('Enabled', 'vcr-am-fiscal-receipts') : __('Disabled', 'vcr-am-fiscal-receipts'),
            __('Default cashier configured', 'vcr-am-fiscal-receipts') => $this->config->defaultCashierId() !== null ? __('Yes', 'vcr-am-fiscal-receipts') : __('No', 'vcr-am-fiscal-receipts'),
            __('Default department configured', 'vcr-am-fiscal-receipts') => $this->config->defaultDepartmentId() !== null ? __('Yes', 'vcr-am-fiscal-receipts') : __('No', 'vcr-am-fiscal-receipts'),
            __('Shipping SKU configured', 'vcr-am-fiscal-receipts') => $this->config->shippingSku() !== null ? __('Yes', 'vcr-am-fiscal-receipts') : __('No', 'vcr-am-fiscal-receipts'),
            __('Fee SKU configured', 'vcr-am-fiscal-receipts') => $this->config->feeSku() !== null ? __('Yes', 'vcr-am-fiscal-receipts') : __('No', 'vcr-am-fiscal-receipts'),
            __('Fully configured', 'vcr-am-fiscal-receipts') => $this->config->isFullyConfigured() ? __('Yes', 'vcr-am-fiscal-receipts') : __('No', 'vcr-am-fiscal-receipts'),
        ];

        // Action Scheduler queue health — pending action counts per hook.
        // Using as_get_scheduled_actions because it's the documented API
        // and works regardless of which AS storage backend is active.
        $rows[__('Pending sale jobs', 'vcr-am-fiscal-receipts')] = (string) $this->countPendingActions(FiscalQueue::ACTION_HOOK);
        $rows[__('Pending refund jobs', 'vcr-am-fiscal-receipts')] = (string) $this->countPendingActions(RefundQueue::ACTION_HOOK);

        // Fiscal status counts — answer "are there orders piling up?"
        // Class constants accessed directly (not through $this->meta) for
        // PHPStan: instance property access via :: makes the type opaque.
        $saleCounts = $this->countByStatus(FiscalStatusMeta::META_STATUS);
        $rows[__('Sale orders — Pending', 'vcr-am-fiscal-receipts')] = (string) ($saleCounts[FiscalStatus::Pending->value] ?? 0);
        $rows[__('Sale orders — Failed', 'vcr-am-fiscal-receipts')] = (string) ($saleCounts[FiscalStatus::Failed->value] ?? 0);
        $rows[__('Sale orders — Manual required', 'vcr-am-fiscal-receipts')] = (string) ($saleCounts[FiscalStatus::ManualRequired->value] ?? 0);

        $refundCounts = $this->countByStatus(RefundStatusMeta::META_STATUS);
        $rows[__('Refunds — Pending', 'vcr-am-fiscal-receipts')] = (string) ($refundCounts[FiscalStatus::Pending->value] ?? 0);
        $rows[__('Refunds — Failed', 'vcr-am-fiscal-receipts')] = (string) ($refundCounts[FiscalStatus::Failed->value] ?? 0);
        $rows[__('Refunds — Manual required', 'vcr-am-fiscal-receipts')] = (string) ($refundCounts[FiscalStatus::ManualRequired->value] ?? 0);

        return $rows;
    }

    private function countPendingActions(string $hook): int
    {
        if (! function_exists('as_get_scheduled_actions')) {
            return 0;
        }

        $count = as_get_scheduled_actions(
            [
                'hook' => $hook,
                'status' => 'pending',
                'per_page' => 0, // 0 = return count instead of records
            ],
            'ids',
        );

        return is_array($count) ? count($count) : (int) $count;
    }

    /**
     * @return array<string, int>  status value -> row count
     */
    private function countByStatus(string $metaKey): array
    {
        /** @var \wpdb|null $wpdb */
        global $wpdb;

        if (! $wpdb instanceof \wpdb) {
            return [];
        }

        $table = $this->orderMetaTable($wpdb);

        // Direct meta query — wc_get_orders with a meta_query is slower
        // because it fetches whole orders before counting. We need only
        // counts grouped by status value.
        //
        // The `%i` placeholder (WP 6.2+) safely escapes the table name
        // identifier — preferred over string interpolation since it
        // produces a literal-string SQL template that PHPStan can
        // verify, and protects against any future change in table
        // naming. `%s` for the meta_key is the standard string
        // placeholder; the value is a class constant, never user input.
        $sql = $wpdb->prepare(
            'SELECT meta_value, COUNT(*) AS c FROM %i WHERE meta_key = %s GROUP BY meta_value',
            $table,
            $metaKey,
        );

        if (! is_string($sql)) {
            return [];
        }

        // $sql was just produced by $wpdb->prepare() above with %i (table)
        // and %s (meta key) placeholders — both safe. PCP can't track
        // the data flow across the `is_string` narrowing, hence the
        // ignore. The cache-comment-style ignore is the documented
        // WPCS escape hatch for this exact pattern.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the literal output of $wpdb->prepare() above.
        $results = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($results)) {
            return [];
        }

        $counts = [];
        foreach ($results as $row) {
            if (! is_array($row) || ! isset($row['meta_value'], $row['c'])) {
                continue;
            }

            $rawMetaValue = $row['meta_value'];
            $metaValue = is_string($rawMetaValue) ? $rawMetaValue : (
                is_scalar($rawMetaValue) ? (string) $rawMetaValue : ''
            );
            if ($metaValue === '') {
                continue;
            }
            $rawCount = $row['c'];
            $count = match (true) {
                is_int($rawCount) => $rawCount,
                is_string($rawCount) && ctype_digit($rawCount) => (int) $rawCount,
                default => 0,
            };
            $counts[$metaValue] = $count;
        }

        return $counts;
    }

    /**
     * Remove `user:pass@` userinfo from a URL before exposing it via
     * the system-status report. Most installs never have credentials
     * embedded in the URL, but a misguided admin who pastes
     * `https://api:secret@vcr.am/...` would otherwise leak the secret
     * to anyone they paste the System Status report to. Strip
     * defensively.
     */
    private function stripCredentials(string $url): string
    {
        $parts = wp_parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        if (! isset($parts['user']) && ! isset($parts['pass'])) {
            return $url;
        }

        $rebuilt = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        if (isset($parts['path'])) {
            $rebuilt .= $parts['path'];
        }
        if (isset($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }

        return $rebuilt;
    }

    /**
     * Resolve the order-meta table name based on whether HPOS is the
     * authoritative store. See class doc-block for full rationale.
     */
    private function orderMetaTable(\wpdb $wpdb): string
    {
        $orderUtil = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';

        if (
            class_exists($orderUtil)
            && is_callable([$orderUtil, 'custom_orders_table_usage_is_enabled'])
            && $orderUtil::custom_orders_table_usage_is_enabled()
        ) {
            // HPOS table — see WC's `OrdersTableDataStoreMeta`.
            return $wpdb->prefix . 'wc_orders_meta';
        }

        return $wpdb->postmeta;
    }
}
