<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterPairingRequestInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\PairedRegister;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\PairingRequest;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The slice of the SDK's `PairingClient` this plugin uses.
 *
 * Same rationale as {@see \BlobSolutions\WooCommerceVcrAm\Catalog\OfferLister}:
 * the SDK class is `final`, so callers talk to this interface and tests inject
 * a double instead of standing up Guzzle and a redirect.
 */
interface PairingGateway
{
    public function registerRequest(RegisterPairingRequestInput $input): PairingRequest;

    public function exchangeCode(string $code, string $codeVerifier): PairedRegister;
}
