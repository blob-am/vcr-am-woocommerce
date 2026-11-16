<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Support;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\SupportReportInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\SupportReportReceipt;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Not declared `final` so unit tests can mock it.
 */
class ReportSubmitterFactory
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly VcrClientFactory $clientFactory,
    ) {
    }

    public function create(string $apiKey): ReportSubmitter
    {
        $client = $this->clientFactory->create(
            apiKey: $apiKey,
            baseUrl: $this->configuration->baseUrl(),
        );

        return new class ($client) implements ReportSubmitter {
            public function __construct(
                private readonly VcrClient $client,
            ) {
            }

            public function submit(SupportReportInput $input): SupportReportReceipt
            {
                return $this->client->submitSupportReport($input);
            }
        };
    }
}
