<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Currency\Exception;

use RuntimeException;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Raised when the plugin cannot obtain a usable AMD conversion rate for a
 * given currency. Possible reasons:
 *
 *   - No API key is configured, so the VCR cannot be asked.
 *   - The VCR is unreachable, or answered with an error.
 *   - The requested ISO code is one the VCR will not quote (AMD itself, an
 *     obscure currency, or a typo in the order's currency).
 *
 * Treat as terminal-for-this-attempt: the upstream caller should mark
 * the order ManualRequired (via {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException})
 * rather than retry — the same exception will recur on the next attempt
 * until the VCR answers again, and we don't want to burn the retry budget on
 * something the admin must see.
 */
class ExchangeRateUnavailableException extends RuntimeException
{
}
