<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleResponse;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Strongly-typed read/write API over the `_vcr_*` meta keys we attach to a
 * {@see WC_Order}. Encapsulates the meta-key namespace so callers never
 * literal-string the keys (typos = silent data loss with WC's loose meta
 * API).
 *
 * Three guarantees the rest of the fiscal layer relies on:
 *
 *   1. The `_underscore` prefix keeps these keys hidden from the default
 *      WC custom-fields admin UI — they belong to the plugin, not to the
 *      shop owner editing arbitrary metadata.
 *   2. `markSuccess()` is the *only* method that writes the SRC
 *      identifiers (urlId / crn / fiscal / saleId / srcReceiptId).
 *   3. Status transitions go through dedicated methods — no public
 *      `setStatus()` — so the call site doubles as a transition log.
 *
 * The "external id" we record on first enqueue is currently informational
 * (the SDK does not yet expose a sale-level external_id field on the wire).
 * Once it does, this is where the deterministic `order_<id>` value will be
 * read for the request payload to give us true server-side idempotency.
 */
/**
 * Not declared `final` so unit tests for FiscalJob and FiscalQueue can
 * mock the meta layer without touching real WC orders.
 */
class FiscalStatusMeta
{
    public const META_STATUS = '_vcr_fiscal_status';

    public const META_ATTEMPT_COUNT = '_vcr_attempt_count';

    public const META_LAST_ERROR = '_vcr_last_error';

    public const META_LAST_ATTEMPT_AT = '_vcr_last_attempt_at';

    public const META_EXTERNAL_ID = '_vcr_external_id';

    public const META_URL_ID = '_vcr_url_id';

    public const META_CRN = '_vcr_crn';

    public const META_FISCAL = '_vcr_fiscal';

    public const META_SALE_ID = '_vcr_sale_id';

    public const META_SRC_RECEIPT_ID = '_vcr_src_receipt_id';

    public const META_REGISTERED_AT = '_vcr_registered_at';

    public function status(WC_Order $order): ?FiscalStatus
    {
        $raw = $order->get_meta(self::META_STATUS, true);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return FiscalStatus::tryFrom($raw);
    }

    public function attemptCount(WC_Order $order): int
    {
        $raw = $order->get_meta(self::META_ATTEMPT_COUNT, true);

        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
            return (int) $raw;
        }

        return 0;
    }

    public function lastError(WC_Order $order): ?string
    {
        $raw = $order->get_meta(self::META_LAST_ERROR, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function externalId(WC_Order $order): string
    {
        $raw = $order->get_meta(self::META_EXTERNAL_ID, true);

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        // Lazy-allocate on first read so orders that pre-date this plugin
        // (or were imported) still get a deterministic id when re-fiscalised.
        return self::buildExternalId($order->get_id());
    }

    public function urlId(WC_Order $order): ?string
    {
        $raw = $order->get_meta(self::META_URL_ID, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function crn(WC_Order $order): ?string
    {
        $raw = $order->get_meta(self::META_CRN, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function fiscal(WC_Order $order): ?string
    {
        $raw = $order->get_meta(self::META_FISCAL, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * SRC's server-side sale id assigned by `registerSale`. Required
     * input for `registerSaleRefund`. Returns null if the parent order
     * was never successfully registered.
     */
    public function saleId(WC_Order $order): ?int
    {
        $raw = $order->get_meta(self::META_SALE_ID, true);

        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    public function srcReceiptId(WC_Order $order): ?int
    {
        $raw = $order->get_meta(self::META_SRC_RECEIPT_ID, true);

        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    public function registeredAt(WC_Order $order): ?string
    {
        $raw = $order->get_meta(self::META_REGISTERED_AT, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function lastAttemptAt(WC_Order $order): ?string
    {
        $raw = $order->get_meta(self::META_LAST_ATTEMPT_AT, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * First-time enqueue: stamps the external id (immutable from this
     * point), sets the initial Pending status, zeroes the attempt count.
     * No-op on subsequent calls — we don't reset the status of an
     * already-tracked order here (that's the job of {@see retry()} or
     * {@see manualReenqueue()}).
     */
    public function initialize(WC_Order $order): void
    {
        if ($this->status($order) !== null) {
            return;
        }

        $order->update_meta_data(self::META_EXTERNAL_ID, self::buildExternalId($order->get_id()));
        $order->update_meta_data(self::META_STATUS, FiscalStatus::Pending->value);
        $order->update_meta_data(self::META_ATTEMPT_COUNT, '0');
        $order->save();
    }

    public function recordAttempt(WC_Order $order): void
    {
        $next = $this->attemptCount($order) + 1;
        $order->update_meta_data(self::META_ATTEMPT_COUNT, (string) $next);
        $order->update_meta_data(self::META_LAST_ATTEMPT_AT, $this->nowIso8601());
        $order->save();
    }

    public function markSuccess(WC_Order $order, RegisterSaleResponse $response): void
    {
        $order->update_meta_data(self::META_STATUS, FiscalStatus::Success->value);
        $order->update_meta_data(self::META_URL_ID, $response->urlId);
        $order->update_meta_data(self::META_CRN, $response->crn);
        $order->update_meta_data(self::META_FISCAL, $response->fiscal);
        $order->update_meta_data(self::META_SALE_ID, (string) $response->saleId);
        $order->update_meta_data(self::META_SRC_RECEIPT_ID, (string) $response->srcReceiptId);
        $order->update_meta_data(self::META_REGISTERED_AT, $this->nowIso8601());
        $order->update_meta_data(self::META_LAST_ERROR, '');
        $order->save();
    }

    /**
     * Retriable failure — keep the order in {@see FiscalStatus::Pending} so
     * the next scheduled tick picks it up. Records the error message on the
     * order so the admin can see it without trawling logs.
     */
    public function markRetriableFailure(WC_Order $order, string $errorMessage): void
    {
        $order->update_meta_data(self::META_STATUS, FiscalStatus::Pending->value);
        $order->update_meta_data(self::META_LAST_ERROR, $errorMessage);
        $order->save();
    }

    public function markFailed(WC_Order $order, string $errorMessage): void
    {
        $order->update_meta_data(self::META_STATUS, FiscalStatus::Failed->value);
        $order->update_meta_data(self::META_LAST_ERROR, $errorMessage);
        $order->save();
    }

    public function markManualRequired(WC_Order $order, string $reason): void
    {
        $order->update_meta_data(self::META_STATUS, FiscalStatus::ManualRequired->value);
        $order->update_meta_data(self::META_LAST_ERROR, $reason);
        $order->save();
    }

    /**
     * Wipe the terminal-state markers so the next call to
     * {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue::enqueue()}
     * treats the order as fresh and schedules a new attempt.
     *
     * Used by the admin "Fiscalize now" button (Phase 3c) after a Failed
     * or ManualRequired outcome — the admin has presumably fixed
     * whatever was broken (missing SKU, bad config, transient SRC
     * outage) and wants another go.
     *
     * Preserves the external id (audit trail and future server-side
     * idempotency) but clears all attempt counters and last-error so
     * the next run starts from a clean slate. Success identifiers
     * (urlId / crn / fiscal) are also untouched here — they would
     * already be empty for any non-Success state, and the caller is
     * expected to gate this method on terminal failure status only.
     */
    public function resetForRetry(WC_Order $order): void
    {
        $order->delete_meta_data(self::META_STATUS);
        $order->update_meta_data(self::META_ATTEMPT_COUNT, '0');
        $order->update_meta_data(self::META_LAST_ERROR, '');
        $order->save();
    }

    /**
     * Wipe ALL plugin-owned `_vcr_*` meta from the order. Used by the
     * GDPR eraser path when the order never resulted in a successful SRC
     * registration (Pending / Failed / ManualRequired / no status at
     * all) — there's no fiscal record to retain on legal-obligation
     * grounds, so the local stale state is just incidental personal data
     * the plugin is responsible for cleaning up.
     *
     * Does NOT touch `_vcr_external_id` / `_vcr_url_id` / `_vcr_crn` /
     * `_vcr_fiscal` / `_vcr_sale_id` / `_vcr_src_receipt_id` /
     * `_vcr_registered_at` for orders that DO have a Success record —
     * the caller (PrivacyHandler) is responsible for the status branch.
     * This method always wipes; gating belongs upstream.
     */
    public function purgeAll(WC_Order $order): void
    {
        $order->delete_meta_data(self::META_STATUS);
        $order->delete_meta_data(self::META_ATTEMPT_COUNT);
        $order->delete_meta_data(self::META_LAST_ERROR);
        $order->delete_meta_data(self::META_LAST_ATTEMPT_AT);
        $order->delete_meta_data(self::META_EXTERNAL_ID);
        $order->delete_meta_data(self::META_URL_ID);
        $order->delete_meta_data(self::META_CRN);
        $order->delete_meta_data(self::META_FISCAL);
        $order->delete_meta_data(self::META_SALE_ID);
        $order->delete_meta_data(self::META_SRC_RECEIPT_ID);
        $order->delete_meta_data(self::META_REGISTERED_AT);
        $order->save();
    }

    /**
     * Build the deterministic external id for a given WC order. Centralised
     * so the same value is used everywhere we'd want to identify the sale
     * from the outside (logs, future SRC idempotency, support tickets).
     */
    public static function buildExternalId(int $orderId): string
    {
        return 'order_' . $orderId;
    }

    private function nowIso8601(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
