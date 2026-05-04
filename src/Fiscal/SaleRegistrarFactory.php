<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterSaleInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleResponse;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;

/**
 * Builds a fresh {@see SaleRegistrar} for each fiscal-job invocation.
 *
 * "Fresh per call" is intentional: workers can run for hours, the API key
 * may rotate via the settings page mid-run, and Guzzle clients are cheap
 * to construct. A single long-lived HTTP client would also tie us to a
 * single TLS connection that may not survive idle gaps between attempts.
 *
 * Adapter pattern: the inner class wraps the SDK's `VcrClient` (which is
 * `final` and therefore unmockable directly) and exposes only the slice
 * {@see FiscalJob} actually uses. Test code mocks the {@see SaleRegistrar}
 * interface and never touches this factory or the SDK.
 *
 * Not declared `final` so the factory itself can be mocked in unit tests
 * — there's no production extension point.
 */
class SaleRegistrarFactory
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly VcrClientFactory $clientFactory,
    ) {
    }

    /**
     * Build a registrar bound to an explicit API key.
     *
     * The key is passed in, not read from {@see Configuration} here, so
     * the caller (FiscalJob) is the single source of truth for "do we
     * have credentials". Without this split, a key that disappeared
     * between FiscalJob's `isFullyConfigured()` gate and the factory
     * call would surface as a generic `RuntimeException` and route to
     * the retry path — wasting the entire backoff budget on something
     * that needs admin intervention. The factory deliberately has no
     * null-key fallback for that reason.
     *
     * Base URL still comes from configuration: it's a deployment-level
     * setting that doesn't change per-job and has a sensible default.
     */
    public function create(string $apiKey): SaleRegistrar
    {
        $client = $this->clientFactory->create(
            apiKey: $apiKey,
            baseUrl: $this->configuration->baseUrl(),
        );

        return new class ($client) implements SaleRegistrar {
            public function __construct(
                private readonly VcrClient $client,
            ) {
            }

            public function registerSale(RegisterSaleInput $input): RegisterSaleResponse
            {
                return $this->client->registerSale($input);
            }
        };
    }
}
