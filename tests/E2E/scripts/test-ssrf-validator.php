<?php

/**
 * Test fixture: invoke SafeUrlValidator::reject() against a list of
 * URLs and echo the per-URL verdict as JSON. Used by the SSRF E2E
 * spec to verify the production code (not a unit-test stub) blocks
 * unsafe URLs at the WC settings save filter.
 *
 * Args: list of URLs to test (positional). One JSON line per URL on
 * stdout: `{"url": "...", "rejected": true|false, "reason": "..."|null}`.
 */

$args = $args ?? [];
if ($args === []) {
    fwrite(STDERR, "no URLs provided\n");
    exit(1);
}

$validator = new \BlobSolutions\WooCommerceVcrAm\Net\SafeUrlValidator();

$results = [];
foreach ($args as $url) {
    $reason = $validator->reject((string) $url);
    $results[] = [
        'url' => $url,
        'rejected' => $reason !== null,
        'reason' => $reason,
    ];
}

echo wp_json_encode($results, JSON_PRETTY_PRINT) . "\n";
