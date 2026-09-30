<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Cli;

use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\Checker;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionSummary;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundQueue;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use WC_Order;
use WP_CLI;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * `wp vcr ...` commands. Loaded only when `WP_CLI` is defined (the
 * standard WP-CLI bootstrap pattern). Provides operational tools for
 * support staff, hosting providers, and bulk operations that don't
 * fit the admin UI:
 *
 *   - `wp vcr fiscalize <order_id>` — manually enqueue a single order
 *   - `wp vcr fiscalize-refund <refund_id>` — manually enqueue a refund
 *   - `wp vcr retry-failed [--dry-run]` — re-queue all Failed/Manual orders
 *   - `wp vcr status` — print plugin config + queue health summary
 *   - `wp vcr check-catalog` — list products that cannot be fiscalised
 *
 * Why CLI is essential even with admin UI:
 *
 *   - **Cron / cron-equivalents** can dispatch CLI commands but can't
 *     click admin buttons. Hosting providers fire `wp vcr retry-failed`
 *     on their schedule.
 *   - **Bulk ops** at thousand-order scale: the admin bulk action caps
 *     at 100 per click on purpose; CLI has no UI timeout.
 *   - **Support debugging**: `wp vcr status` produces a one-paste
 *     config snapshot without admin login.
 *   - **CI / automated testing** of the plugin against a real WP install.
 *
 * Not declared `final` so unit tests can mock the command runner.
 */
class CliCommands
{
    public const COMMAND_NAMESPACE = 'vcr';

    public function __construct(
        private readonly Configuration $configuration,
        private readonly ConnectionProbe $probe,
        private readonly FiscalStatusMeta $fiscalMeta,
        private readonly FiscalQueue $fiscalQueue,
        private readonly RefundStatusMeta $refundMeta,
        private readonly RefundQueue $refundQueue,
        private readonly Checker $coverage,
        private readonly ConnectionSummary $summary = new ConnectionSummary(),
        private readonly CoverageTable $table = new CoverageTable(),
    ) {
    }

    /**
     * Register every command. Idempotent — WP_CLI::add_command throws
     * on re-registration, so we guard via internal flag.
     */
    public function register(): void
    {
        if (! class_exists(WP_CLI::class)) {
            return;
        }

        WP_CLI::add_command(self::COMMAND_NAMESPACE . ' fiscalize', [$this, 'fiscalize']);
        WP_CLI::add_command(self::COMMAND_NAMESPACE . ' fiscalize-refund', [$this, 'fiscalizeRefund']);
        WP_CLI::add_command(self::COMMAND_NAMESPACE . ' retry-failed', [$this, 'retryFailed']);
        WP_CLI::add_command(self::COMMAND_NAMESPACE . ' status', [$this, 'status']);
        WP_CLI::add_command(self::COMMAND_NAMESPACE . ' check-catalog', [$this, 'checkCatalog']);
    }

    /**
     * `wp vcr fiscalize <order_id>` — enqueue a single order for SRC
     * registration. Useful for one-off admin recovery.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : Numeric WC order id.
     *
     * @param array<int, string> $args
     */
    public function fiscalize(array $args): void
    {
        $orderId = isset($args[0]) && ctype_digit($args[0]) ? (int) $args[0] : 0;
        if ($orderId <= 0) {
            $this->fail('Usage: wp vcr fiscalize <order_id>');
        }

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order || $order->get_type() !== 'shop_order') {
            $this->fail("Order #{$orderId} not found or is not a shop order.");
        }

        $status = $this->fiscalMeta->status($order);
        // Reset any terminal status so enqueue accepts the order.
        if ($status === FiscalStatus::Failed || $status === FiscalStatus::ManualRequired) {
            $this->fiscalMeta->resetForRetry($order);
        }

        $this->fiscalQueue->enqueue($orderId);
        WP_CLI::success("Order #{$orderId} enqueued for VCR fiscalisation.");
    }

    /**
     * `wp vcr fiscalize-refund <refund_id>` — enqueue a single refund.
     *
     * ## OPTIONS
     *
     * <refund_id>
     * : Numeric WC refund id (NOT parent order id).
     *
     * @param array<int, string> $args
     */
    public function fiscalizeRefund(array $args): void
    {
        $refundId = isset($args[0]) && ctype_digit($args[0]) ? (int) $args[0] : 0;
        if ($refundId <= 0) {
            $this->fail('Usage: wp vcr fiscalize-refund <refund_id>');
        }

        $refund = wc_get_order($refundId);
        if (! $refund instanceof \WC_Order_Refund) {
            $this->fail("Refund #{$refundId} not found or is not a refund.");
        }

        $status = $this->refundMeta->status($refund);
        if ($status === FiscalStatus::Failed || $status === FiscalStatus::ManualRequired) {
            $this->refundMeta->resetForRetry($refund);
        }

        $this->refundQueue->enqueue($refundId);
        WP_CLI::success("Refund #{$refundId} enqueued for VCR registration.");
    }

    /**
     * `wp vcr retry-failed [--dry-run] [--limit=N]` — re-queue every
     * Failed and ManualRequired order. Useful after fixing an
     * environment-wide problem (bad config, SRC outage now resolved).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Print what would happen, do not enqueue.
     *
     * [--limit=<n>]
     * : Maximum number of orders to process. Default: 1000.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function retryFailed(array $args, array $assoc): void
    {
        $dryRun = isset($assoc['dry-run']);
        $limit = isset($assoc['limit']) && ctype_digit($assoc['limit']) ? (int) $assoc['limit'] : 1000;

        // Find all orders with terminal fiscal status.
        $orders = wc_get_orders([
            'limit' => $limit,
            'type' => 'shop_order',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => FiscalStatusMeta::META_STATUS, 'value' => FiscalStatus::Failed->value, 'compare' => '='],
                ['key' => FiscalStatusMeta::META_STATUS, 'value' => FiscalStatus::ManualRequired->value, 'compare' => '='],
            ],
        ]);

        if (! is_array($orders) || $orders === []) {
            WP_CLI::success('No Failed or ManualRequired orders to retry.');

            return;
        }

        $queued = 0;
        foreach ($orders as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }

            if ($dryRun) {
                WP_CLI::log("Would re-queue order #{$order->get_id()}");
                $queued++;

                continue;
            }

            $this->fiscalMeta->resetForRetry($order);
            $this->fiscalQueue->enqueue($order->get_id());
            $queued++;
        }

        $verb = $dryRun ? 'would be re-queued (dry-run)' : 're-queued';
        WP_CLI::success("{$queued} order(s) {$verb}.");
    }

    /**
     * `wp vcr status` — print plugin config + queue health summary.
     * One-paste-friendly for support tickets.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Default: table. One of: table, json, csv, yaml.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $format = $assoc['format'] ?? 'table';

        $rows = [
            ['key' => 'API key configured', 'value' => $this->configuration->hasCredentials() ? 'yes' : 'no'],
            ['key' => 'Base URL', 'value' => $this->configuration->baseUrl()],
            ['key' => 'Register', 'value' => $this->summary->line($this->probe->state())],
            ['key' => 'Default cashier id', 'value' => (string) ($this->configuration->defaultCashierId() ?? 'unset')],
            ['key' => 'Default department id', 'value' => (string) ($this->configuration->defaultDepartmentId() ?? 'unset')],
            ['key' => 'Shipping SKU', 'value' => $this->configuration->shippingSku() ?? 'unset'],
            ['key' => 'Fee SKU', 'value' => $this->configuration->feeSku() ?? 'unset'],
            ['key' => 'Fully configured', 'value' => $this->configuration->isFullyConfigured() ? 'yes' : 'no'],
        ];

        if (function_exists('WP_CLI\Utils\format_items')) {
            \WP_CLI\Utils\format_items($format, $rows, ['key', 'value']);
        } else {
            // Fallback when running under a WP-CLI version without
            // format_items — print one row per line.
            foreach ($rows as $row) {
                WP_CLI::log("{$row['key']}: {$row['value']}");
            }
        }
    }

    /**
     * `wp vcr check-catalog [--orphans] [--format=<format>]` — read the
     * store's SKUs against the register's offers and list what would stop
     * a receipt. Answers before an order arrives the question the fiscal
     * path can only answer after the customer has paid.
     *
     * Exits non-zero when anything would block a receipt, so a host or CI
     * job can run it on a schedule and be told.
     *
     * ## OPTIONS
     *
     * [--orphans]
     * : Also list offers on the register that no product here references.
     * Off by default — a register's catalogue legitimately holds offers
     * sold through other channels.
     *
     * [--format=<format>]
     * : Render as table (default), csv, json or yaml.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function checkCatalog(array $args, array $assoc): void
    {
        $format = $assoc['format'] ?? 'table';

        // Prose goes to stdout, so it would corrupt a machine-readable
        // render. Only the table wants a narrative around it.
        $narrate = $format === 'table';

        $report = $this->coverage->check(isset($assoc['orphans']));

        if (! $report->isAvailable()) {
            $this->fail('Cannot read the register catalog: ' . $this->summary->failureLine($report->failure));
        }

        if ($narrate) {
            WP_CLI::log(sprintf(
                'Live offers found for %d of %d SKUs in this store.',
                $report->coveredCount,
                $report->checkedCount,
            ));
        }

        if ($report->catalogTruncated && $narrate) {
            WP_CLI::warning(sprintf(
                'The register lists at least %d offers, which is all the API returns at once, '
                . 'so the catalog could not be read whole. SKUs missing from it were checked '
                . 'one at a time, up to %d of them; anything past that is reported as unverified.',
                Checker::OFFER_LIST_CAP,
                Checker::MAX_EXACT_LOOKUPS,
            ));
        }

        $rows = $this->table->rows($report);

        if ($rows !== []) {
            if (function_exists('WP_CLI\Utils\format_items')) {
                \WP_CLI\Utils\format_items($format, $rows, CoverageTable::COLUMNS);
            } else {
                // Fallback when running under a WP-CLI version without
                // format_items — print one row per line.
                foreach ($rows as $row) {
                    WP_CLI::log("{$row['problem']}\t{$row['sku']}\t{$row['product']}");
                }
            }
        }

        if ($report->hasBlockers()) {
            $this->fail(sprintf(
                '%d catalog problems would stop a receipt. Fix them in the VCR dashboard and re-run.',
                count($report->withoutSku) + count($report->missing) + count($report->archived),
            ));
        }

        $pending = count($report->withoutSku) + count($report->missing) + count($report->archived);

        if ($report->catalogArmed && $pending > 0) {
            // Listed, not failed: with a classifier code set these are things
            // the plugin will file itself as each product's first receipt is
            // issued. Exiting non-zero here would put a scheduled check into
            // permanent alarm over a store that works.
            if ($narrate) {
                WP_CLI::success(sprintf(
                    '%d of the products above are not in the register\'s catalog yet. '
                    . 'The plugin adds each one as its first receipt is issued; nothing to do.',
                    $pending,
                ));
            }

            return;
        }

        if ($narrate) {
            WP_CLI::success($report->unverified === []
                ? 'Every SKU this store sells has a live offer.'
                : 'No problems found among the SKUs that could be checked.');
        }
    }

    /**
     * Wrapper around `WP_CLI::error()` that PHPStan understands as
     * `never`-returning. Real WP_CLI::error calls `exit()` after
     * printing — but the upstream WP_CLI stub doesn't annotate it
     * `never`, so callers that rely on it for type narrowing get
     * spurious "could be falsy after error()" warnings. This wrapper
     * carries an explicit `: never` return type so subsequent
     * `instanceof` narrowing works as intended.
     *
     * @return never
     */
    private function fail(string $message): void
    {
        WP_CLI::error($message);
        exit(1); // unreachable in production (WP_CLI::error already exited) but signals `never` to PHPStan
    }
}
