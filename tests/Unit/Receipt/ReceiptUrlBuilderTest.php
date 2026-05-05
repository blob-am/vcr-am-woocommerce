<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Receipt\ReceiptUrlBuilder;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    // Default site locale is `hy_AM` — VCR is Armenia-first. Individual
    // tests override when they need to prove other-language behaviour.
    Functions\when('get_locale')->justReturn('hy_AM');
});

/**
 * Convenience builder used across tests so the per-test setup stays
 * focused on whatever the test is actually trying to prove.
 *
 * Uses `array_key_exists` rather than `??` so tests can deliberately
 * pass `null` for a key (and have it stick) instead of being silently
 * swapped for the default.
 *
 * @param  array<string, mixed> $metaReturns  status / crn / urlId
 */
function makeBuilder(string $apiBase = 'https://vcr.am/api/v1', array $metaReturns = []): array
{
    $status = array_key_exists('status', $metaReturns) ? $metaReturns['status'] : FiscalStatus::Success;
    $crn = array_key_exists('crn', $metaReturns) ? $metaReturns['crn'] : 'CRN-123';
    $urlId = array_key_exists('urlId', $metaReturns) ? $metaReturns['urlId'] : 'rcpt-abc';

    $config = Mockery::mock(Configuration::class);
    $config->allows('baseUrl')->andReturn($apiBase);

    $meta = Mockery::mock(FiscalStatusMeta::class);
    $meta->allows('status')->andReturn($status);
    $meta->allows('crn')->andReturn($crn);
    $meta->allows('urlId')->andReturn($urlId);

    $order = Mockery::mock(WC_Order::class);

    return [new ReceiptUrlBuilder($config, $meta), $order];
}

it('returns a fully-formed receipt URL for a successfully fiscalised order', function (): void {
    [$builder, $order] = makeBuilder();

    expect($builder->build($order))->toBe('https://vcr.am/hy/r/CRN-123/rcpt-abc');
});

it('returns null when status is anything other than Success', function (): void {
    foreach ([FiscalStatus::Pending, FiscalStatus::Failed, FiscalStatus::ManualRequired] as $status) {
        [$builder, $order] = makeBuilder(metaReturns: ['status' => $status]);

        expect($builder->build($order))->toBeNull();
    }
});

it('returns null when status is unset (order never enqueued)', function (): void {
    [$builder, $order] = makeBuilder(metaReturns: ['status' => null]);

    expect($builder->build($order))->toBeNull();
});

it('returns null when crn is missing even if status says Success', function (): void {
    // Defensive guard — markSuccess() always writes both, but a partial
    // restore from backup or a botched manual edit could land us here.
    [$builder, $order] = makeBuilder(metaReturns: ['crn' => null]);

    expect($builder->build($order))->toBeNull();
});

it('returns null when urlId is missing', function (): void {
    [$builder, $order] = makeBuilder(metaReturns: ['urlId' => null]);

    expect($builder->build($order))->toBeNull();
});

it('lets the vcr_receipt_locale filter override site locale (e.g. WPML/Polylang glue)', function (): void {
    Filters\expectApplied('vcr_receipt_locale')
        ->andReturnUsing(fn (string $raw, $order): string => 'ru_RU');

    [$builder, $order] = makeBuilder();

    expect($builder->build($order))->toBe('https://vcr.am/ru/r/CRN-123/rcpt-abc');
});

it('falls back to WP site locale when no order-level locale meta', function (): void {
    Functions\when('get_locale')->justReturn('hy_AM');

    [$builder, $order] = makeBuilder();

    expect($builder->build($order))->toBe('https://vcr.am/hy/r/CRN-123/rcpt-abc');
});

it('falls back to "hy" for unknown locales (Armenia-first default)', function (): void {
    Functions\when('get_locale')->justReturn('fr_FR');

    [$builder, $order] = makeBuilder();

    expect($builder->build($order))->toBe('https://vcr.am/hy/r/CRN-123/rcpt-abc');
});

it('maps en_GB / en_US / plain en all to "en"', function (): void {
    foreach (['en_GB', 'en_US', 'en'] as $loc) {
        Functions\when('get_locale')->justReturn($loc);
        [$builder, $order] = makeBuilder();

        expect($builder->build($order))->toBe('https://vcr.am/en/r/CRN-123/rcpt-abc');
    }
});

it('derives the host from the configured API base URL with port preserved', function (): void {
    [$builder, $order] = makeBuilder(apiBase: 'http://localhost:3000/api/v1');

    expect($builder->build($order))->toBe('http://localhost:3000/hy/r/CRN-123/rcpt-abc');
});

it('handles a non-default vcr.am subdomain (staging / sandbox deployments)', function (): void {
    [$builder, $order] = makeBuilder(apiBase: 'https://staging.vcr.am/api/v1');

    expect($builder->build($order))->toBe('https://staging.vcr.am/hy/r/CRN-123/rcpt-abc');
});

it('falls back to the raw base URL when parse_url cannot extract scheme+host', function (): void {
    // Garbage input — shouldn't crash, lets the filter still get a shot.
    [$builder, $order] = makeBuilder(apiBase: 'not-a-url');

    expect($builder->build($order))->toBe('not-a-url/hy/r/CRN-123/rcpt-abc');
});

it('rawurlencodes crn and urlId so exotic characters do not break the path', function (): void {
    [$builder, $order] = makeBuilder(metaReturns: [
        'crn' => 'CRN/with spaces',
        'urlId' => 'id?with#chars',
    ]);

    $url = $builder->build($order);

    expect($url)
        ->toContain('CRN%2Fwith%20spaces')
        ->toContain('id%3Fwith%23chars');
});

it('lets the vcr_receipt_url filter override the final URL', function (): void {
    Filters\expectApplied('vcr_receipt_url')
        ->once()
        ->andReturn('https://override.example/receipt/xyz');

    [$builder, $order] = makeBuilder();

    expect($builder->build($order))->toBe('https://override.example/receipt/xyz');
});

it('falls back to the derived URL when the filter returns a non-string or empty', function (): void {
    // Misbehaving filter shouldn't break receipt links.
    Filters\expectApplied('vcr_receipt_url')->andReturn(false);

    [$builder, $order] = makeBuilder();

    expect($builder->build($order))->toBe('https://vcr.am/hy/r/CRN-123/rcpt-abc');
});

it('lets the vcr_receipt_host filter override host derivation', function (): void {
    Filters\expectApplied('vcr_receipt_host')
        ->andReturnUsing(fn (string $derived, string $apiBase): string => 'https://customhost.example');

    $config = Mockery::mock(Configuration::class);
    $config->allows('baseUrl')->andReturn('https://vcr.am/api/v1');
    $meta = Mockery::mock(FiscalStatusMeta::class);

    expect((new ReceiptUrlBuilder($config, $meta))->host())
        ->toBe('https://customhost.example');
});
