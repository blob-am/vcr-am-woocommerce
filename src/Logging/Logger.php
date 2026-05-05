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
     * Context keys that, by their NAMES, are known to carry PII the
     * plugin should never persist to log files. The check is by exact
     * key name (case-insensitive); values are blanket-replaced with
     * `[REDACTED]` before being passed to wc_get_logger.
     *
     * Rationale: log files land in `wp-content/uploads/wc-logs/`,
     * are visible at WooCommerce → Status → Logs, and are routinely
     * pasted into support tickets and forum threads. Anything that
     * leaks here is a Schrems-style incident waiting to happen.
     *
     * The list mirrors WC's `WC_Order` getter shape (`get_billing_*`,
     * `get_customer_*`) plus common synonyms a careless caller might
     * use. Add to this list rather than relaxing the regex below — a
     * specific named field is the most reliable redaction.
     */
    private const PII_KEY_NAMES = [
        'billing_email', 'billing_phone', 'billing_first_name', 'billing_last_name',
        'billing_company', 'billing_address_1', 'billing_address_2',
        'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
        'shipping_first_name', 'shipping_last_name', 'shipping_company',
        'shipping_address_1', 'shipping_address_2', 'shipping_city',
        'shipping_state', 'shipping_postcode', 'shipping_country', 'shipping_phone',
        'customer_ip_address', 'customer_user_agent', 'customer_note',
        'email', 'phone', 'first_name', 'last_name', 'name', 'address',
        'ip', 'user_agent',
    ];

    /**
     * Patterns to apply to STRING values regardless of key name. Catches
     * the case where an SDK exception message carries an embedded email
     * or phone the caller couldn't have anticipated. The same regexes
     * power {@see \BlobSolutions\WooCommerceVcrAm\Refund\RefundReasonSanitizer}
     * — kept duplicated rather than shared because the sanitiser is in
     * the refund domain (different concern, different filter).
     */
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\\-]+@[A-Za-z0-9.\\-]+\\.[A-Za-z]{2,}/';

    private const PHONE_PATTERN = '/(?:\\+?\\d[\\d\\s().\\-]{6,}\\d)/';

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
        // into the log entry alongside the message. Run BOTH the message
        // and every context value through the redactor.
        $logger->log(
            $level,
            $this->redactString($message),
            array_merge(['source' => self::SOURCE], $this->redactContext($context)),
        );
    }

    /**
     * Walk a context array and redact:
     *   - any value whose KEY matches a known PII field name (case-insensitive),
     *   - any STRING value (regardless of key) whose content matches an
     *     email or phone-shaped substring.
     *
     * Nested arrays are recursed (one log call frequently includes a
     * `payload` sub-array with structured fields). Non-array, non-string
     * values (ints, bools, objects) are passed through unchanged because
     * those types cannot themselves carry PII the way a string can.
     *
     * @param  array<int|string, mixed> $context
     * @return array<int|string, mixed>
     */
    public function redactContext(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::PII_KEY_NAMES, true)) {
                $out[$key] = '[REDACTED]';

                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->redactContext($value);

                continue;
            }
            if (is_string($value)) {
                $out[$key] = $this->redactString($value);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Apply email + phone redaction patterns to a free-form string.
     */
    private function redactString(string $value): string
    {
        $redacted = preg_replace(self::EMAIL_PATTERN, '[EMAIL_REDACTED]', $value);
        $redacted = is_string($redacted) ? $redacted : $value;
        $redacted = preg_replace(self::PHONE_PATTERN, '[PHONE_REDACTED]', $redacted);

        return is_string($redacted) ? $redacted : $value;
    }
}
