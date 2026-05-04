<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterSaleInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleResponse;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;
use RuntimeException;

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

    public function create(): SaleRegistrar
    {
        $apiKey = $this->configuration->apiKey();

        if ($apiKey === null) {
            // Caller (FiscalJob) is expected to gate on isFullyConfigured()
            // before invoking us; this is belt-and-braces in case someone
            // skips that check or wires us into a flow that doesn't.
            throw new RuntimeException('Cannot build SaleRegistrar without an API key.');
        }

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
