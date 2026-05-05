<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Currency;

use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;

/**
 * Domain facade for "convert this WC order amount to AMD". Wraps an
 * {@see ExchangeRateProvider} and centralises the AMD passthrough.
 *
 * Why a thin wrapper instead of having callers use ExchangeRateProvider
 * directly:
 *
 *   - The AMD passthrough ("don't fetch a rate; you're already in AMD")
 *     belongs on the converter, not on every caller. The provider
 *     wouldn't know that "AMD" is the home currency.
 *   - Centralises the rounding decision. Armenian fiscal receipts are
 *     denominated in whole AMD (no subunit); converted amounts must be
 *     rounded to two decimals at most for SDK acceptance and stamped
 *     to whole AMD on the customer-facing receipt by SRC. Two decimal
 *     places is the SDK's effective ceiling — the converter applies it.
 *   - Future extensions (rounding mode toggle, audit trail of which
 *     rate was applied, multi-rate strategies) get one place to live.
 */
class CurrencyConverter
{
    public const HOME_CURRENCY = 'AMD';

    public function __construct(
        private readonly ExchangeRateProvider $rates,
    ) {
    }

    /**
     * Convert `$amount` from the given source currency to AMD.
     *
     * @throws ExchangeRateUnavailableException
     */
    public function toAmd(float $amount, string $sourceIso): float
    {
        $sourceIso = strtoupper(trim($sourceIso));

        if ($sourceIso === self::HOME_CURRENCY) {
            return $amount;
        }

        $rate = $this->rates->getRate($sourceIso);

        // round() to 2dp — the SDK's decimal-string regex tolerates more
        // precision but real fiscal receipts in AMD never need fractions
        // smaller than a banked qopiq (1/100 AMD). Two decimals also
        // matches the precision PaymentMapper uses for AMD totals.
        return round($amount * $rate->unitToAmd(), 2);
    }
}
