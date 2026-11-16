<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Journal;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Diagnostics\RequestAttempt;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Diagnostics\RequestObserver;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Writes one journal line per call the plugin makes to VCR.AM.
 *
 * The line carries the server's trace id, which is what lets support find the
 * same call in the server's own log. It holds the method, the path and how
 * the call ended; never a header, a body or a query string.
 */
final class ApiCallRecorder implements RequestObserver
{
    public const EVENT = 'api.call';

    public function __construct(
        private readonly Journal $journal,
    ) {
    }

    public function onRequestAttempt(RequestAttempt $attempt): void
    {
        $this->journal->record(
            self::EVENT,
            null,
            [
                'method' => $attempt->method,
                'path' => $attempt->path,
                'status' => $attempt->statusCode,
                'duration_ms' => $attempt->durationMs,
                'failure' => $attempt->failure,
            ],
            $attempt->traceId,
        );
    }
}
