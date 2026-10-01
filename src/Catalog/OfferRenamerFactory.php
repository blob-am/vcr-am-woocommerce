<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Catalog;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\OfferTitle;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Builds a fresh {@see OfferRenamer} per call, mirroring
 * {@see OfferListerFactory} -- same "the API key may rotate mid-run and Guzzle
 * clients are cheap" rationale.
 *
 * Not declared `final` so unit tests can mock the factory itself; there's no
 * production extension point.
 */
class OfferRenamerFactory
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly VcrClientFactory $clientFactory,
    ) {
    }

    public function create(string $apiKey): OfferRenamer
    {
        $client = $this->clientFactory->create(
            apiKey: $apiKey,
            baseUrl: $this->configuration->baseUrl(),
        );

        return new class ($client) implements OfferRenamer {
            public function __construct(
                private readonly VcrClient $client,
            ) {
            }

            public function rename(int $offerId, string $title): void
            {
                // Universal, because that is the only shape the plugin ever
                // creates: one language-agnostic string. A title a merchant has
                // localised in VCR is three rows the plugin cannot express, and
                // OfferTitleSync refuses to overwrite one.
                $this->client->updateOffer($offerId, OfferTitle::universal($title));
            }
        };
    }
}
