<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Admin;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundQueue;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;

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
 * Counts are computed via `wc_get_orders` with a meta query — relies
 * on WP's index over postmeta `meta_key` for performance. For shops
 * with millions of orders this is still O(matching meta rows); a future
 * elaboration could memoise via a transient.
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
            . '<h2>' . esc_html__('VCR — Fiscal Receipts (Armenia)', 'vcr') . '</h2>'
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
            __('Plugin version', 'vcr') => $this->pluginVersion,
            __('API key configured', 'vcr') => $this->config->hasCredentials() ? __('Yes', 'vcr') : __('No', 'vcr'),
            __('Base URL', 'vcr') => $this->config->baseUrl(),
            __('Test mode', 'vcr') => $this->config->isTestMode() ? __('Enabled', 'vcr') : __('Disabled', 'vcr'),
            __('Default cashier configured', 'vcr') => $this->config->defaultCashierId() !== null ? __('Yes', 'vcr') : __('No', 'vcr'),
            __('Default department configured', 'vcr') => $this->config->defaultDepartmentId() !== null ? __('Yes', 'vcr') : __('No', 'vcr'),
            __('Shipping SKU configured', 'vcr') => $this->config->shippingSku() !== null ? __('Yes', 'vcr') : __('No', 'vcr'),
            __('Fee SKU configured', 'vcr') => $this->config->feeSku() !== null ? __('Yes', 'vcr') : __('No', 'vcr'),
            __('Fully configured', 'vcr') => $this->config->isFullyConfigured() ? __('Yes', 'vcr') : __('No', 'vcr'),
        ];

        // Action Scheduler queue health — pending action counts per hook.
        // Using as_get_scheduled_actions because it's the documented API
        // and works regardless of which AS storage backend is active.
        $rows[__('Pending sale jobs', 'vcr')] = (string) $this->countPendingActions(FiscalQueue::ACTION_HOOK);
        $rows[__('Pending refund jobs', 'vcr')] = (string) $this->countPendingActions(RefundQueue::ACTION_HOOK);

        // Fiscal status counts — answer "are there orders piling up?"
        // Class constants accessed directly (not through $this->meta) for
        // PHPStan: instance property access via :: makes the type opaque.
        $saleCounts = $this->countByStatus(FiscalStatusMeta::META_STATUS);
        $rows[__('Sale orders — Pending', 'vcr')] = (string) ($saleCounts[FiscalStatus::Pending->value] ?? 0);
        $rows[__('Sale orders — Failed', 'vcr')] = (string) ($saleCounts[FiscalStatus::Failed->value] ?? 0);
        $rows[__('Sale orders — Manual required', 'vcr')] = (string) ($saleCounts[FiscalStatus::ManualRequired->value] ?? 0);

        $refundCounts = $this->countByStatus(RefundStatusMeta::META_STATUS);
        $rows[__('Refunds — Pending', 'vcr')] = (string) ($refundCounts[FiscalStatus::Pending->value] ?? 0);
        $rows[__('Refunds — Failed', 'vcr')] = (string) ($refundCounts[FiscalStatus::Failed->value] ?? 0);
        $rows[__('Refunds — Manual required', 'vcr')] = (string) ($refundCounts[FiscalStatus::ManualRequired->value] ?? 0);

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

        // Direct postmeta query — wc_get_orders with a meta_query is
        // slower because it fetches whole orders before counting. We
        // need only counts grouped by status value.
        //
        // The `%i` placeholder (WP 6.2+) safely escapes the table name
        // identifier — preferred over string interpolation since it
        // produces a literal-string SQL template that PHPStan can
        // verify, and protects against any future change in postmeta
        // table naming. `%s` for the meta_key is the standard string
        // placeholder; the value is a class constant, never user input.
        $sql = $wpdb->prepare(
            'SELECT meta_value, COUNT(*) AS c FROM %i WHERE meta_key = %s GROUP BY meta_value',
            $wpdb->postmeta,
            $metaKey,
        );

        if (! is_string($sql)) {
            return [];
        }

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
}
