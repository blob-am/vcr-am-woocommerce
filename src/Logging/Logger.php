<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Logging;

/**
 * Thin wrapper around `wc_get_logger()` — the WooCommerce-standard
 * logging channel. Logs land in `wp-content/uploads/wc-logs/vcr-*.log`
 * and are visible in the admin UI under WooCommerce → Status → Logs,
 * filtered by source `'vcr'`.
 *
 * Why a wrapper rather than calling `wc_get_logger()` inline:
 *
 *   - Single place to fix the source/channel name. The 'vcr' string
 *     would otherwise be sprinkled across half a dozen call sites.
 *   - Testable seam — production code depends on this class, tests
 *     mock it without standing up the WC logger / filesystem stack.
 *   - Defensive against absence: if `wc_get_logger` doesn't exist
 *     (unit-test environment without WC bootstrap), we silently
 *     no-op rather than crash. Production always has WC loaded
 *     because `Plugin::onPluginsLoaded` gates everything on it.
 *
 * Why we don't use `add_order_note()` for these messages:
 *
 *   Order notes are customer-visible audit trail of what happened to
 *   the order ("payment received", "order completed", "fiscal receipt
 *   registered"). Retry mechanics ("attempt 3/6 failed: HTTP 503") are
 *   internal diagnostics — they belong in the operational log channel
 *   that support staff filter through during incidents, not in the
 *   note stream that customers see in My Account → View Order.
 *
 * Not declared `final` so unit tests can mock the class for downstream
 * orchestrators (FiscalJob, RefundJob) — there's no production
 * extension point.
 */
class Logger
{
    public const SOURCE = 'vcr';

    /**
     * Log levels mirror PSR-3 / WC's WC_Log_Levels:
     * emergency / alert / critical / error / warning / notice / info / debug
     */
    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();

        // WC's logger expects `source` in context to route to the right
        // log file. Adding more context keys is fine — they get serialised
        // into the log entry alongside the message.
        $logger->log($level, $message, array_merge(['source' => self::SOURCE], $context));
    }
}
