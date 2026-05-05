<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Builds a fresh {@see CashierLister} for each call. Mirrors
 * {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrarFactory}'s
 * pattern: caller passes the API key explicitly, the base URL still
 * comes from {@see Configuration} (deployment-level setting).
 *
 * "Fresh per call" matches the SaleRegistrar rationale — the API key
 * may rotate via the settings page mid-run, and Guzzle clients are
 * cheap to construct.
 *
 * Not declared `final` so unit tests can mock the factory itself —
 * there's no production extension point.
 */
class CashierListerFactory
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly VcrClientFactory $clientFactory,
    ) {
    }

    /**
     * @param ?string $baseUrlOverride Optional ad-hoc base URL — used by
     *                                 the connection-test AJAX so admins
     *                                 can probe a not-yet-saved staging
     *                                 endpoint. `null` (the default)
     *                                 means use the saved configuration,
     *                                 which is what production catalog
     *                                 fetches always want.
     */
    public function create(string $apiKey, ?string $baseUrlOverride = null): CashierLister
    {
        $client = $this->clientFactory->create(
            apiKey: $apiKey,
            baseUrl: $baseUrlOverride ?? $this->configuration->baseUrl(),
        );

        return new class ($client) implements CashierLister {
            public function __construct(
                private readonly VcrClient $client,
            ) {
            }

            public function listCashiers(): array
            {
                return $this->client->listCashiers();
            }
        };
    }
}
