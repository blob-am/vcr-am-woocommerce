<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;
use BlobSolutions\WooCommerceVcrAm\Vendor\GuzzleHttp\Client as GuzzleClient;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Single source of truth for `VcrClient` construction.
 *
 * Centralises the Guzzle timeout configuration so the connection-test
 * AJAX endpoint, the cached catalog fetcher, and (in Phase 3b) the
 * fiscal job worker all hit the API with consistent timeouts. Without
 * this, each call site would default to Guzzle's `timeout: 0` (no
 * upper bound) and we'd discover that asymmetry only when SRC has a
 * bad day.
 *
 * Stateless — the factory has no `Configuration` dependency because
 * `ConnectionTester` needs to build clients from form-typed values
 * that aren't yet persisted. Callers that work with saved config
 * resolve the credentials themselves and pass them in.
 */
/**
 * Not declared `final` so unit tests can mock the factory via Mockery —
 * there's no production extension point.
 */
class VcrClientFactory
{
    /**
     * Hard ceiling on a full request round-trip including TLS handshake,
     * server-side processing, and response transmission. Matches PHP's
     * default `max_execution_time` on most hosts so the AJAX request
     * can't outlive the script that fired it.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * TLS/TCP connect must complete in this window. Much shorter than
     * the request timeout so we fail fast on dead endpoints (typo'd
     * base URL, firewall block, DNS failure) without making the caller
     * wait the full request budget.
     */
    public const DEFAULT_CONNECT_TIMEOUT_SECONDS = 10;

    public function create(
        string $apiKey,
        ?string $baseUrl = null,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
    ): VcrClient {
        $guzzle = new GuzzleClient([
            'timeout' => $timeoutSeconds,
            'connect_timeout' => $connectTimeoutSeconds,
        ]);

        return new VcrClient(
            apiKey: $apiKey,
            baseUrl: $baseUrl ?? VcrClient::DEFAULT_BASE_URL,
            httpClient: $guzzle,
        );
    }
}
