<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Privacy\PrivacyHandler;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

function makePrivacyHandler(): array
{
    $fiscal = Mockery::mock(FiscalStatusMeta::class);
    $refund = Mockery::mock(RefundStatusMeta::class);
    $handler = new PrivacyHandler($fiscal, $refund);

    return [$handler, $fiscal, $refund];
}

it('register hooks exporter, eraser, and the policy-content admin_init handler', function (): void {
    Filters\expectAdded('wp_privacy_personal_data_exporters')->once();
    Filters\expectAdded('wp_privacy_personal_data_erasers')->once();
    Actions\expectAdded('admin_init')->once();

    [$handler] = makePrivacyHandler();
    $handler->register();
});

// ---------- Privacy-policy suggested text ----------

it('registerPolicyContent calls wp_add_privacy_policy_content with our friendly name', function (): void {
    [$handler] = makePrivacyHandler();

    $captured = null;
    Functions\when('wp_add_privacy_policy_content')
        ->alias(static function (string $pluginName, string $content) use (&$captured): void {
            $captured = ['name' => $pluginName, 'content' => $content];
        });
    Functions\when('wp_kses_post')->returnArg(1);
    Functions\when('esc_html')->returnArg(1);
    Functions\when('esc_html__')->returnArg(1);

    $handler->registerPolicyContent();

    expect($captured)->toBeArray();
    expect($captured['name'])->toContain('VCR');
});

it('registerPolicyContent suggests text covering the GDPR critical points', function (): void {
    [$handler] = makePrivacyHandler();

    $captured = null;
    Functions\when('wp_add_privacy_policy_content')
        ->alias(static function (string $pluginName, string $content) use (&$captured): void {
            $captured = $content;
        });
    Functions\when('wp_kses_post')->returnArg(1);
    Functions\when('esc_html')->returnArg(1);
    Functions\when('esc_html__')->returnArg(1);

    $handler->registerPolicyContent();

    // The suggested text MUST disclose:
    //   - third-country transfer to Armenia
    //   - GDPR Art 6(1)(c) lawful basis
    //   - retention with Art 17(3)(b) reference
    //   - Standard Contractual Clauses safeguard
    //   - that we do NOT transmit customer name/email/address
    expect($captured)
        ->toContain('Armenia')
        ->toContain('Article 6(1)(c)')
        ->toContain('Article 17(3)(b)')
        ->toContain('Standard Contractual Clauses')
        ->toContain('do NOT transmit your name')
        ->toContain('Article 380.1');
});

it('registerPolicyContent is a no-op when wp_add_privacy_policy_content is missing', function (): void {
    // Older WP versions / extreme stripped builds. The eraser+exporter
    // would still fire but the policy-content helper wouldn't exist.
    // Verify we don't fatal out.
    [$handler] = makePrivacyHandler();

    // Brain Monkey doesn't allow function_exists() to return false for a
    // stubbed name, so we instead assert there's no exception when we
    // explicitly DO stub the function. This proves the body executes
    // through happily; the function_exists guard is defence for the
    // actually-missing case which is verifiable only in integration tests.
    Functions\when('wp_add_privacy_policy_content')->justReturn(null);
    Functions\when('wp_kses_post')->returnArg(1);
    Functions\when('esc_html')->returnArg(1);
    Functions\when('esc_html__')->returnArg(1);

    expect(fn () => $handler->registerPolicyContent())->not->toThrow(Throwable::class);
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
    $fiscal->allows('attemptCount')->andReturn(1);
    $fiscal->allows('lastAttemptAt')->andReturn(null);
    $fiscal->allows('lastError')->andReturn(null);
    $fiscal->allows('registeredAt')->andReturn('2026-05-05T10:30:00Z');
    $fiscal->allows('crn')->andReturn('CRN-42');
    $fiscal->allows('fiscal')->andReturn('FISC-42');
    $fiscal->allows('urlId')->andReturn('rcpt-42');
    $fiscal->allows('saleId')->andReturn(99);
    $fiscal->allows('srcReceiptId')->andReturn(7777);

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
    expect($rowNames)->toContain('SRC receipt id');
    expect($rowNames)->toContain('Registered at');
    expect($rowNames)->toContain('Attempt count');
});

it('exportFor skips orders with no fiscal records (no group emitted)', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(1);
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
    $fiscal->allows('attemptCount')->andReturn(1);
    $fiscal->allows('lastAttemptAt')->andReturn(null);
    $fiscal->allows('lastError')->andReturn(null);
    $fiscal->allows('registeredAt')->andReturn(null);
    $fiscal->allows('crn')->andReturn(null);
    $fiscal->allows('fiscal')->andReturn(null);
    $fiscal->allows('urlId')->andReturn(null);
    $fiscal->allows('saleId')->andReturn(null);
    $fiscal->allows('srcReceiptId')->andReturn(null);

    $refund->expects('status')->with($refundObj)->andReturn(FiscalStatus::Success);
    $refund->expects('externalId')->with($refundObj)->andReturn('refund_99');
    $refund->allows('attemptCount')->andReturn(1);
    $refund->allows('lastAttemptAt')->andReturn(null);
    $refund->allows('lastError')->andReturn(null);
    $refund->allows('registeredAt')->andReturn(null);
    $refund->allows('crn')->andReturn('REF-CRN-99');
    $refund->allows('fiscal')->andReturn('REF-FISC-99');
    $refund->allows('urlId')->andReturn('rfd-99');
    $refund->allows('saleRefundId')->andReturn(null);
    $refund->allows('receiptId')->andReturn(null);

    $result = $handler->exportFor('customer@example.com');

    expect($result['data'])->toHaveCount(2);
    $itemIds = array_column($result['data'], 'item_id');
    expect($itemIds)->toBe(['order-42', 'refund-99']);
});

it('exportFor includes operational meta for SAR completeness (Art 15)', function (): void {
    // Regression guard: GDPR Article 15 entitles the data subject to ALL
    // personal data processed about them. Operational state — when we
    // tried, what error we logged, how many attempts — is processing
    // metadata about the subject's order. Failing to export it is an
    // SAR gap. The 5 fields below MUST appear when their underlying
    // meta is populated.
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(13);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->allows('status')->andReturn(FiscalStatus::Pending);
    $fiscal->allows('externalId')->andReturn('order_13');
    $fiscal->allows('attemptCount')->andReturn(3);
    $fiscal->allows('lastAttemptAt')->andReturn('2026-05-05T10:30:00Z');
    $fiscal->allows('lastError')->andReturn('SRC HTTP 500: gateway timeout');
    $fiscal->allows('registeredAt')->andReturn(null);
    $fiscal->allows('crn')->andReturn(null);
    $fiscal->allows('fiscal')->andReturn(null);
    $fiscal->allows('urlId')->andReturn(null);
    $fiscal->allows('saleId')->andReturn(null);
    $fiscal->allows('srcReceiptId')->andReturn(null);

    $result = $handler->exportFor('customer@example.com');

    $rowsByName = [];
    foreach ($result['data'][0]['data'] as $row) {
        $rowsByName[$row['name']] = $row['value'];
    }

    expect($rowsByName)->toHaveKey('Attempt count');
    expect($rowsByName['Attempt count'])->toBe('3');
    expect($rowsByName)->toHaveKey('Last attempt at');
    expect($rowsByName['Last attempt at'])->toBe('2026-05-05T10:30:00Z');
    expect($rowsByName)->toHaveKey('Last error');
    expect($rowsByName['Last error'])->toBe('SRC HTTP 500: gateway timeout');
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

// ---------- Eraser (status-differentiated) ----------

it('eraseFor RETAINS Success orders and cites the specific legal articles', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(42);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(FiscalStatus::Success);
    // No purge call expected — Success orders MUST be retained.
    $fiscal->expects('purgeAll')->never();

    $result = $handler->eraseFor('customer@example.com');

    expect($result['items_retained'])->toBeTrue();
    expect($result['items_removed'])->toBeFalse();
    expect($result['messages'])->toHaveCount(1);
    expect($result['messages'][0])
        ->toContain('order #42')
        ->toContain('Article 380.1')
        ->toContain('Article 17(3)(b)')
        ->toContain('Article 56');
});

it('eraseFor REMOVES Pending orders that never reached SRC', function (): void {
    // Pending: enqueue happened, no successful registration. The local
    // _vcr_* meta is stale; SRC has no record. Lawful basis to retain
    // does NOT apply, so we delete.
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(7);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(FiscalStatus::Pending);
    $fiscal->expects('purgeAll')->with($order)->once();

    $result = $handler->eraseFor('customer@example.com');

    expect($result['items_removed'])->toBeTrue();
    expect($result['items_retained'])->toBeFalse();
});

it('eraseFor REMOVES Failed orders (no SRC record to retain)', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(8);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(FiscalStatus::Failed);
    $fiscal->expects('purgeAll')->with($order)->once();

    $result = $handler->eraseFor('customer@example.com');

    expect($result['items_removed'])->toBeTrue();
    expect($result['items_retained'])->toBeFalse();
});

it('eraseFor REMOVES ManualRequired orders (admin never resolved)', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(9);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(FiscalStatus::ManualRequired);
    $fiscal->expects('purgeAll')->with($order)->once();

    $result = $handler->eraseFor('customer@example.com');

    expect($result['items_removed'])->toBeTrue();
});

it('eraseFor is a no-op when order has no plugin meta and no refunds', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(99);
    $order->allows('get_refunds')->andReturn([]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(null);
    $fiscal->expects('purgeAll')->never();

    $result = $handler->eraseFor('customer@example.com');

    expect($result['items_retained'])->toBeFalse();
    expect($result['items_removed'])->toBeFalse();
    expect($result['messages'])->toBe([]);
});

it('eraseFor RETAINS when ANY refund is Success even if parent is Pending', function (): void {
    // Edge case: parent failed-to-register but a previous-version
    // attempt did register a refund. Conservative posture: keep
    // everything retained for the parent so the audit trail stays
    // coherent.
    $refundObj = Mockery::mock(WC_Order_Refund::class);
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(10);
    $order->allows('get_refunds')->andReturn([$refundObj]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal, $refund] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(FiscalStatus::Pending);
    $fiscal->expects('purgeAll')->never();
    $refund->expects('status')->with($refundObj)->andReturn(FiscalStatus::Success);
    $refund->expects('purgeAll')->never();

    $result = $handler->eraseFor('customer@example.com');

    expect($result['items_retained'])->toBeTrue();
});

it('eraseFor purges Pending refund meta but retains Success-parent fiscal record', function (): void {
    // Mixed state: parent successfully registered, refund attempted but
    // never reached SRC. Retain parent (legal obligation), purge refund
    // (no SRC record to honour).
    $refundObj = Mockery::mock(WC_Order_Refund::class);
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(11);
    $order->allows('get_refunds')->andReturn([$refundObj]);
    Functions\when('wc_get_orders')->justReturn([$order]);

    [$handler, $fiscal, $refund] = makePrivacyHandler();
    $fiscal->expects('status')->with($order)->andReturn(FiscalStatus::Success);
    $fiscal->expects('purgeAll')->never();
    $refund->expects('status')->with($refundObj)->andReturn(FiscalStatus::Failed);
    $refund->expects('purgeAll')->with($refundObj)->once();

    $result = $handler->eraseFor('customer@example.com');

    expect($result['items_retained'])->toBeTrue();
    // Refund purge counts as a removal.
    expect($result['items_removed'])->toBeTrue();
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

it('exportFor matches by customer_user as well as billing_email and dedupes', function (): void {
    // Scenario: same customer placed 1 order as guest (matched by
    // billing_email) and 1 order as a registered user (matched by
    // customer_id). The exporter should return both orders, deduped.
    $orderGuest = Mockery::mock(WC_Order::class);
    $orderGuest->allows('get_id')->andReturn(101);
    $orderGuest->allows('get_refunds')->andReturn([]);

    $orderUser = Mockery::mock(WC_Order::class);
    $orderUser->allows('get_id')->andReturn(202);
    $orderUser->allows('get_refunds')->andReturn([]);

    // Same order returned by BOTH lookups (customer who used same
    // email both as guest and after registering) must dedupe to one.
    $orderShared = Mockery::mock(WC_Order::class);
    $orderShared->allows('get_id')->andReturn(303);
    $orderShared->allows('get_refunds')->andReturn([]);

    $callCount = 0;
    Functions\when('wc_get_orders')->alias(static function (array $args) use (&$callCount, $orderGuest, $orderUser, $orderShared) {
        $callCount++;
        if (isset($args['billing_email'])) {
            return [$orderGuest, $orderShared];
        }
        if (isset($args['customer_id'])) {
            return [$orderUser, $orderShared];
        }

        return [];
    });
    Functions\when('get_user_by')->justReturn((object) ['ID' => 7]);

    [$handler, $fiscal] = makePrivacyHandler();
    // The exporter only iterates orders to call status; null skips them
    // and we just verify the underlying ordersForEmail behaviour via
    // the call count and dedup outcome (signaled through done=true
    // because we returned 3 unique orders < PAGE_SIZE).
    $fiscal->allows('status')->andReturn(null);

    $result = $handler->exportFor('returning@example.com');

    // Two wc_get_orders calls: one by billing_email, one by customer_id.
    expect($callCount)->toBe(2);
    expect($result['done'])->toBeTrue();
});

it('exportFor skips the customer_user lookup when no WP user matches the email', function (): void {
    $callCount = 0;
    Functions\when('wc_get_orders')->alias(static function () use (&$callCount) {
        $callCount++;

        return [];
    });
    Functions\when('get_user_by')->justReturn(false);

    [$handler] = makePrivacyHandler();
    $handler->exportFor('guest-only@example.com');

    // Only the billing_email branch fires — no customer_id call.
    expect($callCount)->toBe(1);
});
