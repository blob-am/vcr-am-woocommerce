<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Currency;

use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;

/**
 * Source of CBA exchange rates. Implementations:
 *
 *   - {@see CbaExchangeRateProvider} — live SOAP call to api.cba.am.
 *   - {@see CachedExchangeRateProvider} — decorator that adds a WP-transient
 *     cache + 48-hour staleness gate around any underlying provider.
 *
 * Production wiring composes them: cached(cba). Tests can swap in a
 * pure in-memory implementation without touching the network.
 */
interface ExchangeRateProvider
{
    /**
     * @param  string $iso ISO 4217 three-letter code (e.g. "USD", "EUR").
     * @throws ExchangeRateUnavailableException when no usable rate can be returned.
     */
    public function getRate(string $iso): ExchangeRate;
}
