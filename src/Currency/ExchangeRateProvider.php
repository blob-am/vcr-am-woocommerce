<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Currency;

use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Source of AMD conversion rates.
 *
 * Production wiring uses {@see VcrExchangeRateProvider}, which asks the VCR —
 * the same service that fiscalises the sale — so one implementation of Tax
 * Code art. 16 governs both. Tests swap in a pure in-memory implementation
 * and never touch the network.
 */
interface ExchangeRateProvider
{
    /**
     * @param  string $iso ISO 4217 three-letter code (e.g. "USD", "EUR").
     * @throws ExchangeRateUnavailableException when no usable rate can be returned.
     */
    public function getRate(string $iso): ExchangeRate;
}
