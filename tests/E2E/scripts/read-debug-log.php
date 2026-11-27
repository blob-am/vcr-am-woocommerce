<?php

/**
 * Test fixture: print what debug.log gained past a byte offset.
 *
 * Args: [offset]. Without one, prints only the current size, which a spec
 * records before the requests it wants to attribute lines to.
 *
 * Echoes JSON: `{"size": int, "lines": string[]}`.
 *
 * No strict_types declaration: `wp eval-file` wraps the body in eval(),
 * where it is a parse error. See tests/Unit/E2EFixtureHeadersTest.php.
 */

$path = WP_CONTENT_DIR . '/debug.log';
$offset = isset($args[0]) ? (int) $args[0] : null;

clearstatcache(true, $path);
$size = is_file($path) ? (int) filesize($path) : 0;

$lines = [];
if ($offset !== null && $size > $offset) {
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "debug.log exists but could not be opened\n");
        exit(1);
    }
    fseek($handle, $offset);
    $chunk = stream_get_contents($handle);
    fclose($handle);
    $lines = array_values(array_filter(explode("\n", (string) $chunk), static fn ($line) => trim($line) !== ''));
}

echo wp_json_encode(['size' => $size, 'lines' => $lines]) . "\n";
