<?php

declare(strict_types=1);

/**
 * Prove a built plugin artefact can actually run.
 *
 * Usage: php bin/smoke-test-artifact.php <path to unzipped plugin dir>
 *
 * WordPress is not involved and must not be: this checks the one layer
 * underneath it — that both autoloaders resolve the runtime object graph.
 * The failure this exists to catch (v0.1.0) left every class file present on
 * disk while the generated classmap omitted the PSR contracts, so file-level
 * assertions all passed and the plugin could not make a single HTTP call.
 *
 * `class_exists()` alone is not enough: it returns true for a class whose
 * parent or interface is missing, right up until PHP tries to resolve it.
 * So the last step constructs the client for real.
 */

// Every plugin file opens with `if (! defined('ABSPATH')) { exit; }`, so
// touching one of our own classes outside WordPress terminates this process
// silently — and with status 0, which would make this guard report a pass
// having verified nothing. Stand in for WordPress before loading anything.
if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$root = $argv[1] ?? '';

if ($root === '' || ! is_dir($root)) {
    fwrite(STDERR, "usage: smoke-test-artifact.php <unzipped-plugin-dir>\n");
    exit(2);
}

foreach (['vendor/autoload.php', 'vendor-prefixed/autoload.php'] as $autoloader) {
    $path = $root . '/' . $autoloader;

    if (! is_file($path)) {
        fwrite(STDERR, "FAIL: $autoloader is missing\n");
        exit(1);
    }

    require_once $path;
}

$prefix = 'BlobSolutions\\WooCommerceVcrAm\\';
$vendor = $prefix . 'Vendor\\';

/** @var array<string, string> symbol => kind */
$required = [
    // Shared contracts, deliberately left unprefixed so the scoped Guzzle
    // interoperates with whatever else the site has loaded. These are the
    // ones the broken build dropped.
    'Psr\\Http\\Client\\ClientInterface' => 'interface',
    'Psr\\Http\\Message\\RequestFactoryInterface' => 'interface',
    'Psr\\Http\\Message\\StreamFactoryInterface' => 'interface',
    'Psr\\Log\\LoggerInterface' => 'interface',
    // Scoped dependencies.
    $vendor . 'GuzzleHttp\\Client' => 'class',
    $vendor . 'BlobSolutions\\VcrAm\\VcrClient' => 'class',
    // The plugin's own code.
    $prefix . 'Fiscal\\ItemBuilder' => 'class',
    $prefix . 'Fiscal\\PaymentMapper' => 'class',
];

$failures = 0;

foreach ($required as $symbol => $kind) {
    $found = $kind === 'interface' ? interface_exists($symbol) : class_exists($symbol);

    if (! $found) {
        fwrite(STDERR, sprintf("FAIL: %s %s is not autoloadable\n", $kind, $symbol));
        $failures++;
    }
}

// An unprefixed copy in the global namespace is the collision Strauss exists
// to prevent; it means the scoping step silently did nothing.
foreach (['GuzzleHttp\\Client', 'CuyZ\\Valinor\\MapperBuilder'] as $leaked) {
    if (class_exists($leaked)) {
        fwrite(STDERR, "FAIL: $leaked leaked into the global namespace unscoped\n");
        $failures++;
    }
}

if ($failures > 0) {
    fwrite(STDERR, sprintf("\n%d smoke-test failure(s) — refusing to publish this artefact.\n", $failures));
    exit(1);
}

// Construct for real. Resolving the class is not the same as resolving
// everything it inherits and implements, and only construction proves it.
$clientClass = $vendor . 'GuzzleHttp\\Client';
$client = new $clientClass();

if (! $client instanceof Psr\Http\Client\ClientInterface) {
    fwrite(STDERR, "FAIL: scoped Guzzle does not satisfy the unprefixed PSR-18 contract\n");
    exit(1);
}

echo '    all ' . count($required) . " symbols resolve; scoped client constructs and satisfies PSR-18\n";
echo "SMOKE TEST PASSED\n";
