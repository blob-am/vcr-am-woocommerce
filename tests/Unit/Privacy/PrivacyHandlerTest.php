<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Privacy\PrivacyHandler;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

function makePrivacyHandler(): array
{
    $fiscal = Mockery::mock(FiscalStatusMeta::class);
    $refund = Mockery::mock(RefundStatusMeta::class);
    $handler = new PrivacyHandler($fiscal, $refund);

    return [$handler, $fiscal, $refund];
}

it('register hooks both exporter and eraser filters', function (): void {
    Filters\expectAdded('wp_privacy_personal_data_exporters')->once();
    Filters\expectAdded('wp_privacy_personal_data_erasers')->once();

    [$handler] = makePrivacyHandler();
    $handler->register();
});

it('registerExporter appends our exporter to the WP-supplied array', function (): void {
    [$handler] = makePrivacyHandler();

    $result = $handler->registerExporter(['wc-orders' => ['exporter_friendly_name' => 'Orders']]);

    expect($result)->toHaveKey('wc-orders');
    expect($result)->toHaveKey(PrivacyHandler::EXPORTER_GROUP_ID);
    expect($result[PrivacyHandler::EXPORTER_GROUP_ID]['exporter_friendly_name'])->toBe('VCR Fiscal Receipts');
    expect($result[PrivacyHandler::EXPORTER_GROUP_ID]['callback'])->toBeArray();
});

it('registerEraser appends our eraser to the WP-supplied array', function (): void {
    [$handler] = makePrivacyHandler();

    $result = $handler->registerEraser([]);

    expect($result)->toHaveKey(PrivacyHandler::ERASER_ID);
    expect($result[PrivacyHandler::ERASER_ID]['eraser_friendly_name'])->toBe('VCR Fiscal Receipts');
});

it('registerExporter handles non-array input defensively', function (): void {
    [$handler] = makePrivacyHandler();

    /** @phpstan-ignore-next-line — verifying defensive narrowing */
    $result = $handler->registerExporter(null);

    expect($result)->toHaveKey(PrivacyHandler::EXPORTER_GROUP_ID);
});

// ---------- Exporter ----------

it('exportFor returns empty data and done=true for an unknown email', function (): void {
    Functions\when('wc_get_orders')->justReturn([]);

    [$handler] = makePrivacyHandler();
    $result = $handler->exportFor('unknown@example.com');

    expect($result['data'])->toBe([]);
    expect($result['done'])->toBeTrue();
});

it('exportFor surfaces fiscal identifiers for matching orders', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(42);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal, $refund] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(FiscalStatus::Success);
    $fiscal->expects('externalId')->with($order)->andReturn('order_42');
    $fiscal->expects('crn')->with($order)->andReturn('CRN-42');
    $fiscal->expects('fiscal')->with($order)->andReturn('FISC-42');
    $fiscal->expects('urlId')->with($order)->andReturn('rcpt-42');
    $fiscal->expects('saleId')->with($order)->andReturn(99);

    $result = $handler->exportFor('customer@example.com');

    expect($result['data'])->toHaveCount(1);
    expect($result['data'][0]['group_id'])->toBe(PrivacyHandler::EXPORTER_GROUP_ID);
    expect($result['data'][0]['item_id'])->toBe('order-42');

    // Ensure each identifier appears as a row.
    $rowNames = array_column($result['data'][0]['data'], 'name');
    expect($rowNames)->toContain('Fiscal status');
    expect($rowNames)->toContain('SRC CRN');
    expect($rowNames)->toContain('SRC fiscal serial');
    expect($rowNames)->toContain('SRC sale id');
});

it('exportFor skips orders with no fiscal records (no group emitted)', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(null);

    $result = $handler->exportFor('customer@example.com');

    expect($result['data'])->toBe([]);
});

it('exportFor emits one group per refund alongside the parent group', function (): void {
    $refundObj = Mockery::mock(WC_Order_Refund::class);
    $refundObj->allows('get_id')->andReturn(99);

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(42);
    $order->allows('get_refunds')->andReturn([$refundObj]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal, $refund] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(FiscalStatus::Success);
    $fiscal->expects('externalId')->with($order)->andReturn('order_42');
    $fiscal->allows('crn')->andReturn(null);
    $fiscal->allows('fiscal')->andReturn(null);
    $fiscal->allows('urlId')->andReturn(null);
    $fiscal->allows('saleId')->andReturn(null);

    $refund->expects('status')->with($refundObj)->andReturn(FiscalStatus::Success);
    $refund->expects('externalId')->with($refundObj)->andReturn('refund_99');
    $refund->allows('crn')->andReturn('REF-CRN-99');
    $refund->allows('fiscal')->andReturn('REF-FISC-99');
    $refund->allows('urlId')->andReturn('rfd-99');

    $result = $handler->exportFor('customer@example.com');

    expect($result['data'])->toHaveCount(2);
    $itemIds = array_column($result['data'], 'item_id');
    expect($itemIds)->toBe(['order-42', 'refund-99']);
});

it('exportFor signals done=false when result page is full (more data expected)', function (): void {
    // Build 50 mock orders — exactly the page size.
    $orders = [];
    for ($i = 1; $i <= 50; $i++) {
        $o = Mockery::mock(WC_Order::class);
        $o->allows('get_id')->andReturn($i);
        $o->allows('get_refunds')->andReturn([]);
        $orders[] = $o;
    }
    Functions\when('wc_get_orders')->justReturn($orders);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->allows('status')->andReturn(null);  // skip them all

    $result = $handler->exportFor('customer@example.com');

    expect($result['done'])->toBeFalse();
});

// ---------- Eraser (always retains) ----------

it('eraseFor reports retained=true with a tax-law message for orders with fiscal records', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(42);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(FiscalStatus::Success);

    $result = $handler->eraseFor('customer@example.com');

    expect($result['items_removed'])->toBeFalse();
    expect($result['items_retained'])->toBeTrue();
    expect($result['messages'])->toHaveCount(1);
    expect($result['messages'][0])
        ->toContain('order #42')
        ->toContain('Armenian tax law');
});

it('eraseFor reports retained=false when no fiscal records exist for any order', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(null);

    $result = $handler->eraseFor('customer@example.com');

    expect($result['items_retained'])->toBeFalse();
    expect($result['messages'])->toBe([]);
});

it('eraseFor never sets items_removed=true (we never delete fiscal data)', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(42);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->allows('status')->andReturn(FiscalStatus::Success);

    $result = $handler->eraseFor('customer@example.com');

    // The cardinal compliance invariant — we MUST NOT signal that
    // we removed any data, because we don't (and can't, per tax law).
    expect($result['items_removed'])->toBeFalse();
});

it('exporter / eraser both bail on empty email (defensive)', function (): void {
    [$handler] = makePrivacyHandler();

    $exportResult = $handler->exportFor('');
    $eraseResult = $handler->eraseFor('');

    expect($exportResult['data'])->toBe([]);
    expect($exportResult['done'])->toBeTrue();
    expect($eraseResult['items_removed'])->toBeFalse();
    expect($eraseResult['items_retained'])->toBeFalse();
});
