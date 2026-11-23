<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\ClassifierSearchEndpoint;
use BlobSolutions\WooCommerceVcrAm\Catalog\Classifier\Directory;
use BlobSolutions\WooCommerceVcrAm\Catalog\Classifier\Entry;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('check_ajax_referer')->justReturn(1);
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('wp_unslash')->returnArg();

    // Both helpers end the request in WordPress; throwing keeps that true here.
    Functions\when('wp_send_json_success')->alias(function (array $payload = []): void {
        throw new RuntimeException('json_success_sent: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    });
    Functions\when('wp_send_json_error')->alias(function (array $payload = [], int $status = 0): void {
        throw new RuntimeException('json_error_sent ' . $status . ': ' . ($payload['message'] ?? '?'));
    });

    $_GET = [];
});

afterEach(function (): void {
    $_GET = [];
});

function classifierEndpoint(Directory $directory): ClassifierSearchEndpoint
{
    return new ClassifierSearchEndpoint($directory, '/tmp/plugin.php', '0.1.15');
}

it('registers the AJAX action and the script hook', function (): void {
    Actions\expectAdded('wp_ajax_vcr_search_classifier')->once();
    Actions\expectAdded('admin_enqueue_scripts')->once();

    classifierEndpoint(Mockery::mock(Directory::class))->register();
});

it('answers in the shape selectWoo reads: the code as id, code and name as text', function (): void {
    $directory = Mockery::mock(Directory::class);
    $directory->expects('search')->with('6109')->andReturn([
        new Entry('6109', 'T-shirts'),
        new Entry('61.10'),
    ]);
    $_GET['term'] = '6109';

    expect(fn () => classifierEndpoint($directory)->handle())->toThrow(
        RuntimeException::class,
        'json_success_sent: {"results":[{"id":"6109","text":"6109 — T-shirts"},{"id":"61.10","text":"61.10"}]}',
    );
});

it('does not ask VCR about a single character', function (): void {
    $directory = Mockery::mock(Directory::class);
    $directory->expects('search')->never();
    $_GET['term'] = '4';

    expect(fn () => classifierEndpoint($directory)->handle())
        ->toThrow(RuntimeException::class, 'json_success_sent: {"results":[]}');
});

it('reports a failed search as an error, not as no matches', function (): void {
    $directory = Mockery::mock(Directory::class);
    $directory->expects('search')->andThrow(new RuntimeException('timed out'));
    $_GET['term'] = '6109';

    expect(fn () => classifierEndpoint($directory)->handle())
        ->toThrow(RuntimeException::class, 'json_error_sent 502: timed out');
});

it('refuses a user who cannot manage WooCommerce', function (): void {
    Functions\when('current_user_can')->justReturn(false);
    $directory = Mockery::mock(Directory::class);
    $directory->expects('search')->never();
    $_GET['term'] = '6109';

    expect(fn () => classifierEndpoint($directory)->handle())
        ->toThrow(RuntimeException::class, 'json_error_sent 403');
});

it('enqueue survives admin_enqueue_scripts fired with no arguments', function (): void {
    Functions\expect('wp_enqueue_script')->never();

    classifierEndpoint(Mockery::mock(Directory::class))->enqueue();
});
