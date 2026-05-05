<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Currency;

/**
 * Immutable snapshot of one CBA published exchange rate.
 *
 * CBA quotes some currencies per multi-unit lot (e.g. 100 JPY, 1 IRR
 * group of 1000). The {@see self::amount} captures that lot size; one
 * unit of {@see self::iso} converts to AMD as `rate / amount`. So a
 * `rate=251.6, amount=100` means `1 JPY = 2.516 AMD`.
 *
 * {@see self::publishedAt} is the CBA-side rate-effective date (NOT the
 * fetch time — those can differ if we cached and replayed). Caching of
 * the rate row is done in {@see CachedExchangeRateProvider} and tracks
 * its own `fetchedAt` separately for the 48-hour staleness gate.
 */
final readonly class ExchangeRate
{
    public function __construct(
        public string $iso,
        public float $rate,
        public float $amount,
        public int $publishedAt,
    ) {
    }

    /**
     * Convert one unit of this currency to AMD.
     *
     * Example: `JPY rate=251.6 amount=100` -> `toAmd() = 2.516` (AMD per 1 JPY).
     */
    public function unitToAmd(): float
    {
        return $this->rate / $this->amount;
    }
}
