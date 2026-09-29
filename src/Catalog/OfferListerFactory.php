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
 * Builds a fresh {@see OfferLister} per call, mirroring
 * {@see DepartmentListerFactory} — same "the API key may rotate mid-run
 * and Guzzle clients are cheap" rationale.
 *
 * Not declared `final` so unit tests can mock the factory itself; there's
 * no production extension point.
 */
class OfferListerFactory
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly VcrClientFactory $clientFactory,
    ) {
    }

    public function create(string $apiKey): OfferLister
    {
        $client = $this->clientFactory->create(
            apiKey: $apiKey,
            baseUrl: $this->configuration->baseUrl(),
        );

        return new class ($client) implements OfferLister {
            public function __construct(
                private readonly VcrClient $client,
            ) {
            }

            public function listOffers(?string $externalId = null): array
            {
                return $this->client->listOffers(
                    externalId: $externalId,
                    includeArchived: true,
                );
            }
        };
    }
}
