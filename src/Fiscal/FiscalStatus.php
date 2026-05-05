<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

/**
 * Lifecycle of a single WooCommerce order's fiscalisation flow.
 *
 * Persisted as the string value of the `_vcr_fiscal_status` order meta and
 * read back through {@see FiscalStatusMeta}. The enum is the only writer
 * — never set the meta directly — so transitions stay observable in one
 * place.
 *
 * Transitions:
 *
 *     [no meta]  --enqueue-->  Pending
 *     Pending    --success-->  Success    (terminal)
 *     Pending    --retriable--> Pending   (re-scheduled, attempt++)
 *     Pending    --terminal-->  Failed    (terminal — admin can re-enqueue manually)
 *     Pending    --config gap--> ManualRequired  (terminal until admin fixes settings)
 *     Failed / ManualRequired   --admin "Fiscalize now"-->  Pending
 *
 * `Success` is the only state that carries SRC identifiers (urlId / crn /
 * fiscal). All other states should have those fields cleared in meta —
 * {@see FiscalStatusMeta::markSuccess()} is the only path that writes them.
 */

if (! defined('ABSPATH')) {
    exit;
}

enum FiscalStatus: string
{
    /**
     * Queued for the next worker tick. Either the first attempt or a
     * scheduled retry — distinguish via {@see FiscalStatusMeta::attemptCount()}.
     */
    case Pending = 'pending';

    /** Fiscalised. Receipt identifiers available via {@see FiscalStatusMeta}. */
    case Success = 'success';

    /**
     * Exhausted the retry schedule, OR hit a non-retriable API error
     * (4xx other than 429). Order will not be retried automatically;
     * admin must hit "Fiscalize now" in the order meta box (Phase 3c).
     */
    case Failed = 'failed';

    /**
     * Configuration is incomplete (missing API key, cashier, or
     * department). Distinct from {@see self::Failed} so the admin notice
     * can point at the settings page instead of the SRC error.
     */
    case ManualRequired = 'manual_required';

    public function isTerminal(): bool
    {
        return $this === self::Success
            || $this === self::Failed
            || $this === self::ManualRequired;
    }
}
