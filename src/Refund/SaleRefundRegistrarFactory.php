<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterSaleRefundInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\RegisterSaleRefundResponse;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;

/**
 * Builds a fresh {@see SaleRefundRegistrar} per refund-job invocation.
 * Same rationale as {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrarFactory}:
 * Guzzle clients are cheap, the API key may rotate mid-run, and a
 * single long-lived client risks stale TLS connections.
 *
 * The adapter wraps the SDK's `final` VcrClient and exposes only the
 * one method {@see RefundJob} needs.
 *
 * Not declared `final` so the factory itself can be mocked in unit tests.
 */
class SaleRefundRegistrarFactory
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly VcrClientFactory $clientFactory,
    ) {
    }

    /**
     * See {@see \BlobSolutions\WooCommerceVcrAm\Fiscal\SaleRegistrarFactory::create()}
     * for the rationale on accepting `$apiKey` explicitly: the factory
     * deliberately has no null-key fallback so a missing key surfaces
     * as a configuration error in {@see RefundJob}, not a Guzzle 401.
     */
    public function create(string $apiKey): SaleRefundRegistrar
    {
        $client = $this->clientFactory->create(
            apiKey: $apiKey,
            baseUrl: $this->configuration->baseUrl(),
        );

        return new class ($client) implements SaleRefundRegistrar {
            public function __construct(
                private readonly VcrClient $client,
            ) {
            }

            public function registerSaleRefund(RegisterSaleRefundInput $input): RegisterSaleRefundResponse
            {
                return $this->client->registerSaleRefund($input);
            }
        };
    }
}
