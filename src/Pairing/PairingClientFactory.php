<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

use BlobSolutions\WooCommerceVcrAm\VcrClientFactory;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterPairingRequestInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\PairedRegister;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\PairingRequest;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\PairingClient;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;
use BlobSolutions\WooCommerceVcrAm\Vendor\GuzzleHttp\Client as GuzzleClient;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds the keyless client that pairing runs on.
 *
 * Separate from {@see VcrClientFactory} for the same reason the SDK keeps the
 * two clients apart: pairing has no API key to pass, and `VcrClient` refuses
 * an empty one. The timeouts are deliberately the same, so a merchant waiting
 * on the connect button waits no longer than on the test-connection button.
 *
 * Not declared `final` so unit tests can mock it; there is no production
 * extension point.
 */
class PairingClientFactory
{
    /**
     * @param ?string $integration `User-Agent` product token naming this
     *                             plugin, from {@see \BlobSolutions\WooCommerceVcrAm\IntegrationToken}.
     */
    public function __construct(private readonly ?string $integration = null)
    {
    }

    public function create(?string $baseUrl = null): PairingGateway
    {
        $guzzle = new GuzzleClient([
            'timeout' => VcrClientFactory::DEFAULT_TIMEOUT_SECONDS,
            'connect_timeout' => VcrClientFactory::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        ]);

        $client = new PairingClient(
            baseUrl: $baseUrl ?? VcrClient::DEFAULT_BASE_URL,
            httpClient: $guzzle,
            integration: $this->integration,
        );

        return new class ($client) implements PairingGateway {
            public function __construct(
                private readonly PairingClient $client,
            ) {
            }

            public function registerRequest(RegisterPairingRequestInput $input): PairingRequest
            {
                return $this->client->registerRequest($input);
            }

            public function exchangeCode(string $code, string $codeVerifier): PairedRegister
            {
                return $this->client->exchangeCode($code, $codeVerifier);
            }
        };
    }
}
