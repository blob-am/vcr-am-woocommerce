<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Currency;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use Throwable;

if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are diagnostic, surfaced via Logger or wp_die() (which escape themselves); per-arg esc_html on sprintf args is ritual noise.


/**
 * Asks the VCR for the AMD rate instead of working it out locally.
 *
 * The rate is not a fact to look up, it is the answer to a legal question:
 * Tax Code art. 16 says which day's Central Bank publication governs, and
 * art. 16 is amended by ՀՕ-83-Ն with effect from 2027-01-01. A second
 * implementation of that rule inside the plugin would have to be kept in step
 * with the one the server uses to fiscalise the sale — and when the two
 * disagree, the refund no longer matches the receipt it reverses. So there is
 * exactly one implementation, it lives with the receipts, and the response
 * names the rule version it applied.
 *
 * The practical gains are the same shape: a merchant's host no longer has to
 * reach api.cba.am, only vcr.am, which the refund call needs anyway.
 *
 * Credentials are resolved per call rather than captured at construction, for
 * the reason {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrarFactory}
 * gives: an API key rotated through the settings page mid-run must take effect
 * without a restart.
 *
 * No caching. The old CBA client cached because it depended on a third party
 * that could be slow or down; this call goes to the same host as the refund it
 * serves, so a cached rate would only paper over an outage that fails the
 * refund regardless.
 */
class VcrExchangeRateProvider implements ExchangeRateProvider
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly VcrClientFactory $clientFactory,
    ) {
    }

    public function getRate(string $iso): ExchangeRate
    {
        $iso = strtoupper(trim($iso));
        $apiKey = $this->configuration->apiKey();

        if ($apiKey === null) {
            throw new ExchangeRateUnavailableException(sprintf(
                'Cannot resolve the AMD rate for %s: no VCR API key is configured.',
                $iso,
            ));
        }

        try {
            // Building the client is inside the try on purpose: every way of
            // failing to obtain a rate has to reach the caller as the same
            // exception, or a refund blows up instead of routing to manual
            // registration.
            $client = $this->clientFactory->create(
                apiKey: $apiKey,
                baseUrl: $this->configuration->baseUrl(),
            );

            $rate = $client->getExchangeRate($iso);
        } catch (Throwable $exception) {
            throw new ExchangeRateUnavailableException(
                sprintf(
                    'The VCR could not supply an AMD rate for %s: %s',
                    $iso,
                    $exception->getMessage(),
                ),
                0,
                $exception,
            );
        }

        if ($rate->ratePerUnit <= 0.0) {
            throw new ExchangeRateUnavailableException(sprintf(
                'The VCR returned a non-positive AMD rate (%s) for %s.',
                (string) $rate->ratePerUnit,
                $iso,
            ));
        }

        return new ExchangeRate(
            iso: strtoupper($rate->currency),
            amdPerUnit: $rate->ratePerUnit,
            rateDate: $rate->rateDate,
            ruleVersion: $rate->ruleVersion,
        );
    }
}
