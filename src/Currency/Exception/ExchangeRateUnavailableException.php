<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Currency\Exception;

use RuntimeException;

/**
 * Raised when the plugin cannot obtain a usable Central Bank of Armenia
 * exchange rate for a given currency. Possible reasons:
 *
 *   - CBA endpoint unreachable AND no cached rate is available within
 *     the staleness window (currently 48 hours from {@see CachedExchangeRateProvider}).
 *   - CBA returned a malformed payload (XML parse failure, missing
 *     `<Rate>` element, non-positive rate value).
 *   - The requested currency ISO code is not in CBA's published list
 *     (e.g. an obscure cryptocurrency, a typo in the order's currency).
 *
 * Treat as terminal-for-this-attempt: the upstream caller should mark
 * the order ManualRequired (via {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException})
 * rather than retry — the same exception will recur on the next attempt
 * unless CBA recovers, and we don't want to burn the retry budget on
 * something the admin must see.
 */
class ExchangeRateUnavailableException extends RuntimeException
{
}
