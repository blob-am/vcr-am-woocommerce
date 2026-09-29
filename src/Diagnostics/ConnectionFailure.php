<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * A classified connection failure: what went wrong, plus whatever is worth
 * quoting to support.
 *
 * `requestId` is only ever set for a 5xx, where VCR returns a correlation
 * id that finds the matching server log and Sentry event. `technicalDetail`
 * carries the API's own error sentence when there is one — shown in small
 * print, never in place of the plain-language explanation, and never
 * containing the API key (the SDK's exceptions keep credentials in the
 * request object, not in the message).
 */
final readonly class ConnectionFailure
{
    public function __construct(
        public ConnectionProblem $problem,
        public ?string $requestId = null,
        public ?string $technicalDetail = null,
    ) {
    }
}
