<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionState;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Journal\Entry;
use BlobSolutions\WooCommerceVcrAm\Journal\Journal;
use BlobSolutions\WooCommerceVcrAm\Journal\StoreException;
use BlobSolutions\WooCommerceVcrAm\Refund\RefundStatusMeta;
use BlobSolutions\WooCommerceVcrAm\Support\ReportBuilder;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('home_url')->justReturn('https://shop.example');
    Functions\when('get_bloginfo')->justReturn('7.1.4');
    Functions\when('get_locale')->justReturn('ru_RU');
    Functions\when('wp_parse_url')->alias(fn (string $url) => parse_url($url));

    $this->config = Mockery::mock(Configuration::class);
    $this->config->allows([
        'hasCredentials' => true,
        'baseUrl' => 'https://user:secret@vcr.am/api/v1',
        'defaultCashierId' => 3,
        'defaultDepartmentId' => 5,
        'shippingSku' => null,
        'feeSku' => null,
        'cashFiscalizeOn' => 'processing',
        'isFullyConfigured' => true,
    ]);
    $this->probe = Mockery::mock(ConnectionProbe::class);
    $this->probe->allows('state')->andReturn(ConnectionState::noApiKey());
    $this->meta = Mockery::mock(FiscalStatusMeta::class);
    $this->refundMeta = Mockery::mock(RefundStatusMeta::class);
    $this->journal = Mockery::mock(Journal::class);

    $this->builder = new ReportBuilder(
        '0.2.0',
        $this->config,
        $this->probe,
        $this->meta,
        $this->refundMeta,
        $this->journal,
    );
});

it('describes the store without an order', function (): void {
    $this->journal->expects('recent')->with(null, 200)->andReturn([
        new Entry(2, '2026-11-16 13:14:12', 'order.queued', 630, null, ['order_status' => 'processing']),
    ]);

    $report = $this->builder->build();

    expect($report)->not->toHaveKey('order')
        ->and($report['plugin'])->toBe(['slug' => 'vcr-am-fiscal-receipts', 'version' => '0.2.0'])
        ->and($report['register'])->toBeString()->not->toBe('')
        ->and($report['environment']['site'])->toBe('https://shop.example')
        ->and($report['environment']['wordpress'])->toBe('7.1.4')
        ->and($report['configuration']['baseUrl'])->toBe('https://vcr.am/api/v1')
        ->and($report['configuration']['defaultCashierId'])->toBe(3)
        ->and($report['journal'])->toBe([[
            'at' => '2026-11-16 13:14:12',
            'event' => 'order.queued',
            'orderId' => 630,
            'traceId' => null,
            'data' => ['order_status' => 'processing'],
        ]]);
});

it('describes an order by its state and nothing about the buyer', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows([
        'get_id' => 8402,
        'get_order_number' => '8402',
        'get_status' => 'pending',
        'get_date_created' => null,
        'get_date_paid' => null,
        'get_created_via' => 'checkout',
        'get_payment_method' => 'vtbam',
        'get_payment_method_title' => 'VTB Armenia',
        'get_transaction_id' => '',
        'get_currency' => 'AMD',
        'get_total' => '100',
        'get_items' => ['a' => new stdClass()],
        'get_refunds' => [],
    ]);
    $this->meta->allows([
        'status' => FiscalStatus::Failed,
        'attemptCount' => 2,
        'lastError' => 'Buyer buyer@example.com rejected',
        'lastAttemptAt' => '2026-11-16T13:00:00+00:00',
        'registeredAt' => null,
        'saleId' => null,
        'crn' => null,
        'fiscal' => null,
    ]);
    $this->journal->expects('recent')->with(8402, 200)->andReturn([]);

    $report = $this->builder->build($order);

    expect($report['order'])->toMatchArray([
        'id' => 8402,
        'status' => 'pending',
        'paidAt' => null,
        'paymentMethod' => 'vtbam',
        'hasTransactionId' => false,
        'lineCount' => 1,
        'refunds' => [],
    ])
        ->and($report['order']['fiscal'])->toMatchArray([
            'status' => 'failed',
            'attempts' => 2,
            'lastError' => 'Buyer [EMAIL_REDACTED] rejected',
            'lastAttemptAt' => '2026-11-16T13:00:00+00:00',
        ]);

    $order->shouldNotHaveReceived('get_billing_email');
    $order->shouldNotHaveReceived('get_billing_phone');
    $order->shouldNotHaveReceived('get_billing_first_name');
    $order->shouldNotHaveReceived('get_customer_ip_address');
});

it('fails when the journal cannot be read, instead of reporting an empty one', function (): void {
    $this->journal->allows('recent')->andThrow(new StoreException('table is missing'));

    expect(fn () => $this->builder->build())->toThrow(StoreException::class);
});
