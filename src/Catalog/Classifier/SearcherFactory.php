<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog\Classifier;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Language;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\OfferType;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * A fresh {@see Searcher} per call, for the same reason as
 * {@see \BlobSolutions\WooCommerceVcrAm\Catalog\CashierListerFactory}: the
 * key can change on the settings page mid-request.
 *
 * Not declared `final` so unit tests can mock it.
 */
class SearcherFactory
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly VcrClientFactory $clientFactory,
    ) {
    }

    public function create(string $apiKey): Searcher
    {
        $client = $this->clientFactory->create(
            apiKey: $apiKey,
            baseUrl: $this->configuration->baseUrl(),
        );

        return new class ($client) implements Searcher {
            public function __construct(
                private readonly VcrClient $client,
            ) {
            }

            public function search(string $query, OfferType $type, Language $language): array
            {
                return $this->client->searchClassifier($query, $type, $language);
            }
        };
    }
}
