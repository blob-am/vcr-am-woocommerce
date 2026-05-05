<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Receipt\ReceiptUrlBuilder;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundReceiptUrlBuilder;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('get_locale')->justReturn('hy_AM');
});

/**
 * @param array<string, mixed> $opts
 * @return array{0: RefundReceiptUrlBuilder, 1: WC_Order_Refund}
 */
function makeRefundUrlBuilder(array $opts = []): array
{
    $status = array_key_exists('status', $opts) ? $opts['status'] : FiscalStatus::Success;
    $crn = array_key_exists('crn', $opts) ? $opts['crn'] : 'REF-CRN';
    $urlId = array_key_exists('urlId', $opts) ? $opts['urlId'] : 'rfd-1';
    $parentId = $opts['parent_id'] ?? 50;
    $parent = array_key_exists('parent', $opts) ? $opts['parent'] : Mockery::mock(WC_Order::class);

    if ($parent !== null) {
        Functions\when('wc_get_order')->alias(fn (int $id) => $id === $parentId ? $parent : null);
    } else {
        Functions\when('wc_get_order')->justReturn(null);
    }

    $meta = Mockery::mock(RefundStatusMeta::class);
    $meta->allows('status')->andReturn($status);
    $meta->allows('crn')->andReturn($crn);
    $meta->allows('urlId')->andReturn($urlId);

    // The shared sale-builder is constructed against a fake configuration
    // — we only call its composeUrl method, which uses host() + locale().
    $config = Mockery::mock(\BlobSolutions\WooCommerceVcrAm\Configuration::class);
    $config->allows('baseUrl')->andReturn('https://vcr.am/api/v1');
    $fiscalMeta = Mockery::mock(\BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta::class);
    $sharedBuilder = new ReceiptUrlBuilder($config, $fiscalMeta);

    $refund = Mockery::mock(WC_Order_Refund::class);
    $refund->allows('get_parent_id')->andReturn($parentId);

    return [new RefundReceiptUrlBuilder($sharedBuilder, $meta), $refund];
}

it('returns a fully-formed URL for a successfully-registered refund', function (): void {
    [$builder, $refund] = makeRefundUrlBuilder();

    expect($builder->build($refund))->toBe('https://vcr.am/hy/r/REF-CRN/rfd-1');
});

it('returns null for non-Success status', function (): void {
    foreach ([FiscalStatus::Pending, FiscalStatus::Failed, FiscalStatus::ManualRequired] as $status) {
        [$builder, $refund] = makeRefundUrlBuilder(['status' => $status]);

        expect($builder->build($refund))->toBeNull();
    }
});

it('returns null when status is unset', function (): void {
    [$builder, $refund] = makeRefundUrlBuilder(['status' => null]);

    expect($builder->build($refund))->toBeNull();
});

it('returns null when refund has no CRN (SRC did not issue one)', function (): void {
    // RegisterSaleRefundResponse contract allows null crn — without
    // one we cannot address the receipt route.
    [$builder, $refund] = makeRefundUrlBuilder(['crn' => null]);

    expect($builder->build($refund))->toBeNull();
});

it('returns null when refund has no urlId', function (): void {
    [$builder, $refund] = makeRefundUrlBuilder(['urlId' => null]);

    expect($builder->build($refund))->toBeNull();
});

it('returns null when parent order cannot be resolved (no locale context)', function (): void {
    [$builder, $refund] = makeRefundUrlBuilder(['parent' => null]);

    expect($builder->build($refund))->toBeNull();
});

it('lets the vcr_refund_receipt_url filter override the URL', function (): void {
    Filters\expectApplied('vcr_refund_receipt_url')
        ->andReturn('https://customviewer.example/refund/xyz');

    [$builder, $refund] = makeRefundUrlBuilder();

    expect($builder->build($refund))->toBe('https://customviewer.example/refund/xyz');
});

it('falls back to derived URL when filter returns non-string or empty', function (): void {
    Filters\expectApplied('vcr_refund_receipt_url')->andReturn(false);

    [$builder, $refund] = makeRefundUrlBuilder();

    expect($builder->build($refund))->toBe('https://vcr.am/hy/r/REF-CRN/rfd-1');
});
