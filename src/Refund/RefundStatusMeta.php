<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleRefundResponse;
use WC_Order_Refund;

/**
 * Strongly-typed read/write API over the `_vcr_refund_*` meta keys we
 * attach to a {@see WC_Order_Refund}. Refunds get their OWN meta
 * namespace (separate from the parent {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta})
 * because:
 *
 *   - A single parent order can have N refunds, each independently
 *     fiscalised. Putting refund state on the parent order would either
 *     overwrite or require array-of-meta gymnastics.
 *   - The refund object is what WC's data store invalidates, indexes,
 *     and exports. Co-locating fiscal state with the entity we're
 *     fiscalising keeps the audit trail honest.
 *
 * Reuses {@see FiscalStatus} for the state values — refunds share the
 * same lifecycle (Pending → Success | Failed | ManualRequired) as sales,
 * so a separate enum would be parallel-but-identical without semantic
 * differentiation.
 *
 * Not declared `final` so unit tests for RefundJob and RefundQueue can
 * mock the meta layer.
 */
class RefundStatusMeta
{
    public const META_STATUS = '_vcr_refund_status';

    public const META_ATTEMPT_COUNT = '_vcr_refund_attempt_count';

    public const META_LAST_ERROR = '_vcr_refund_last_error';

    public const META_LAST_ATTEMPT_AT = '_vcr_refund_last_attempt_at';

    public const META_EXTERNAL_ID = '_vcr_refund_external_id';

    public const META_URL_ID = '_vcr_refund_url_id';

    public const META_CRN = '_vcr_refund_crn';

    public const META_FISCAL = '_vcr_refund_fiscal';

    public const META_SALE_REFUND_ID = '_vcr_sale_refund_id';

    public const META_RECEIPT_ID = '_vcr_refund_receipt_id';

    public const META_REGISTERED_AT = '_vcr_refund_registered_at';

    public function status(WC_Order_Refund $refund): ?FiscalStatus
    {
        $raw = $refund->get_meta(self::META_STATUS, true);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return FiscalStatus::tryFrom($raw);
    }

    public function attemptCount(WC_Order_Refund $refund): int
    {
        $raw = $refund->get_meta(self::META_ATTEMPT_COUNT, true);

        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
            return (int) $raw;
        }

        return 0;
    }

    public function lastError(WC_Order_Refund $refund): ?string
    {
        $raw = $refund->get_meta(self::META_LAST_ERROR, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function externalId(WC_Order_Refund $refund): string
    {
        $raw = $refund->get_meta(self::META_EXTERNAL_ID, true);

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        return self::buildExternalId($refund->get_id());
    }

    public function urlId(WC_Order_Refund $refund): ?string
    {
        $raw = $refund->get_meta(self::META_URL_ID, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function crn(WC_Order_Refund $refund): ?string
    {
        $raw = $refund->get_meta(self::META_CRN, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function fiscal(WC_Order_Refund $refund): ?string
    {
        $raw = $refund->get_meta(self::META_FISCAL, true);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * First-time enqueue: stamps the external id, sets initial Pending
     * status, zeroes the attempt count. No-op on subsequent calls.
     */
    public function initialize(WC_Order_Refund $refund): void
    {
        if ($this->status($refund) !== null) {
            return;
        }

        $refund->update_meta_data(self::META_EXTERNAL_ID, self::buildExternalId($refund->get_id()));
        $refund->update_meta_data(self::META_STATUS, FiscalStatus::Pending->value);
        $refund->update_meta_data(self::META_ATTEMPT_COUNT, '0');
        $refund->save();
    }

    public function recordAttempt(WC_Order_Refund $refund): void
    {
        $next = $this->attemptCount($refund) + 1;
        $refund->update_meta_data(self::META_ATTEMPT_COUNT, (string) $next);
        $refund->update_meta_data(self::META_LAST_ATTEMPT_AT, $this->nowIso8601());
        $refund->save();
    }

    public function markSuccess(WC_Order_Refund $refund, RegisterSaleRefundResponse $response): void
    {
        $refund->update_meta_data(self::META_STATUS, FiscalStatus::Success->value);
        $refund->update_meta_data(self::META_URL_ID, $response->urlId);
        // crn / fiscal can be null per SDK contract — store empty string
        // so the boolean meta-presence test stays cheap, and consumer code
        // (UI / receipt link builder) treats empty as "not yet issued".
        $refund->update_meta_data(self::META_CRN, $response->crn ?? '');
        $refund->update_meta_data(self::META_FISCAL, $response->fiscal ?? '');
        $refund->update_meta_data(self::META_SALE_REFUND_ID, (string) $response->saleRefundId);
        $refund->update_meta_data(self::META_RECEIPT_ID, (string) $response->receiptId);
        $refund->update_meta_data(self::META_REGISTERED_AT, $this->nowIso8601());
        $refund->update_meta_data(self::META_LAST_ERROR, '');
        $refund->save();
    }

    public function markRetriableFailure(WC_Order_Refund $refund, string $errorMessage): void
    {
        $refund->update_meta_data(self::META_STATUS, FiscalStatus::Pending->value);
        $refund->update_meta_data(self::META_LAST_ERROR, $errorMessage);
        $refund->save();
    }

    public function markFailed(WC_Order_Refund $refund, string $errorMessage): void
    {
        $refund->update_meta_data(self::META_STATUS, FiscalStatus::Failed->value);
        $refund->update_meta_data(self::META_LAST_ERROR, $errorMessage);
        $refund->save();
    }

    public function markManualRequired(WC_Order_Refund $refund, string $reason): void
    {
        $refund->update_meta_data(self::META_STATUS, FiscalStatus::ManualRequired->value);
        $refund->update_meta_data(self::META_LAST_ERROR, $reason);
        $refund->save();
    }

    /**
     * Wipe terminal-state markers so the next enqueue treats the refund
     * as fresh. Used by the admin "Fiscalize refund now" button.
     * Preserves external id; clears attempt counter and last error.
     */
    public function resetForRetry(WC_Order_Refund $refund): void
    {
        $refund->delete_meta_data(self::META_STATUS);
        $refund->update_meta_data(self::META_ATTEMPT_COUNT, '0');
        $refund->update_meta_data(self::META_LAST_ERROR, '');
        $refund->save();
    }

    public static function buildExternalId(int $refundId): string
    {
        return 'refund_' . $refundId;
    }

    private function nowIso8601(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
