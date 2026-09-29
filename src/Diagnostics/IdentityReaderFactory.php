<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Diagnostics;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrMode;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Builds a fresh {@see IdentityReader} per call, the same way
 * {@see \BlobSolutions\WooCommerceVcrAm\Catalog\CashierListerFactory} does:
 * the key is passed in (it may have just been typed and not yet saved), the
 * base URL comes from configuration unless the caller overrides it.
 *
 * The API-to-domain mapping lives here so nothing above this line imports
 * the SDK's `AccountInfo`.
 *
 * Not declared `final` so unit tests can mock the factory itself — there's
 * no production extension point.
 */
class IdentityReaderFactory
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly VcrClientFactory $clientFactory,
    ) {
    }

    public function create(string $apiKey, ?string $baseUrlOverride = null): IdentityReader
    {
        $client = $this->clientFactory->create(
            apiKey: $apiKey,
            baseUrl: $baseUrlOverride ?? $this->configuration->baseUrl(),
        );

        return new class ($client) implements IdentityReader {
            public function __construct(
                private readonly VcrClient $client,
            ) {
            }

            public function identify(): RegisterIdentity
            {
                $account = $this->client->whoami();

                return new RegisterIdentity(
                    vcrId: $account->vcrId,
                    crn: $account->crn,
                    isSandbox: $account->mode === VcrMode::Sandbox,
                    tradingName: $account->tradingPlatformName,
                    entityName: $account->businessEntity->name,
                    tin: $account->businessEntity->tin,
                );
            }
        };
    }
}
