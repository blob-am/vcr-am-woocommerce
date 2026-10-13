<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Update\UpdateChecker;

it('plugin entry file declares the WordPress plugin header', function (): void {
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/vcr-am-fiscal-receipts.php');

    expect($contents)
        ->toContain('Plugin Name:       VCR — Fiscal Receipts for Armenia (eHDM)')
        ->toContain('Requires PHP:      8.3')
        ->toContain('Requires at least: 6.7')
        ->toContain('WC requires at least: 9.4')
        ->toContain('License:           GPL-2.0-or-later')
        ->toContain('Text Domain:       vcr');
});

it('plugin entry file refuses to execute outside WordPress', function (): void {
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/vcr-am-fiscal-receipts.php');

    expect($contents)->toMatch('/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)\s*\{\s*\n\s*exit;/m');
});

// The header is what WordPress reads to pick the update hook; the constant is
// what the plugin listens on. They are two files apart and a typo in either
// would simply mean no store ever hears about a release again, with nothing
// failing anywhere to say so.
it('declares the same Update URI the update checker listens on', function (): void {
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/vcr-am-fiscal-receipts.php');

    expect($contents)->toContain('Update URI:        ' . UpdateChecker::UPDATE_URI);
});
