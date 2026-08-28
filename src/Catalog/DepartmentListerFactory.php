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
 * Builds a fresh {@see DepartmentLister} per call, mirroring
 * {@see CashierListerFactory} — same "the API key may rotate mid-run and
 * Guzzle clients are cheap" rationale.
 *
 * Not declared `final` so unit tests can mock the factory itself; there's
 * no production extension point.
 */
class DepartmentListerFactory
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly VcrClientFactory $clientFactory,
    ) {
    }

    public function create(string $apiKey): DepartmentLister
    {
        $client = $this->clientFactory->create(
            apiKey: $apiKey,
            baseUrl: $this->configuration->baseUrl(),
        );

        return new class ($client) implements DepartmentLister {
            public function __construct(
                private readonly VcrClient $client,
            ) {
            }

            public function listDepartments(): array
            {
                return $this->client->listDepartments();
            }
        };
    }
}
