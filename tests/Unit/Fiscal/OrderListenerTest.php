<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\CashPaymentResolver;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\OrderListener;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;

beforeEach(function (): void {
    $this->queue = Mockery::mock(FiscalQueue::class);
    $this->config = Mockery::mock(Configuration::class);
    $this->cashResolver = Mockery::mock(CashPaymentResolver::class);

    // Default posture for every test that is not about the cash timing knob:
    // fiscalise as soon as the order is placed, which is the shipped default.
    $this->config->allows('cashFiscalizeOn')
        ->andReturn(Configuration::CASH_FISCALIZE_ON_PROCESSING);

    $this->listener = new OrderListener($this->queue, $this->config, $this->cashResolver);
});

it('register hooks all three WC order-state events', function (): void {
    Actions\expectAdded('woocommerce_payment_complete')->once();
    Actions\expectAdded('woocommerce_order_status_processing')->once();
    Actions\expectAdded('woocommerce_order_status_completed')->once();

    $this->listener->register();
});

it('forwards an int order id to the queue', function (): void {
    $this->queue->expects('enqueue')->once()->with(42);

    $this->listener->onPaymentComplete(42);
});

it('coerces a string-id payload (legacy do_action callers)', function (): void {
    $this->queue->expects('enqueue')->once()->with(42);

    $this->listener->onPaymentComplete('42');
});

it('ignores garbage payloads instead of throwing', function (): void {
    $this->queue->expects('enqueue')->never();

    $this->listener->onPaymentComplete('not a number');
});

it('ignores null payloads', function (): void {
    $this->queue->expects('enqueue')->never();

    $this->listener->onPaymentComplete(null);
});

it('processing handler forwards correctly', function (): void {
    $this->queue->expects('enqueue')->once()->with(7);

    $this->listener->onProcessing(7);
});

it('completed handler forwards correctly', function (): void {
    $this->queue->expects('enqueue')->once()->with(7);

    $this->listener->onCompleted(7);
});

describe('cash-on-delivery timing', function (): void {
    beforeEach(function (): void {
        $this->queue = Mockery::mock(FiscalQueue::class);
        $this->config = Mockery::mock(Configuration::class);
        $this->cashResolver = Mockery::mock(CashPaymentResolver::class);
        $this->config->allows('cashFiscalizeOn')
            ->andReturn(Configuration::CASH_FISCALIZE_ON_COMPLETED);
        $this->listener = new OrderListener($this->queue, $this->config, $this->cashResolver);
    });

    it('defers a cash order at processing when the shop waits for completion', function (): void {
        $order = Mockery::mock(\WC_Order::class);
        $order->allows('get_payment_method')->andReturn('cod');
        Functions\when('wc_get_order')->justReturn($order);
        $this->cashResolver->expects('isCash')->once()->with('cod')->andReturnTrue();

        $this->queue->expects('enqueue')->never();

        $this->listener->onProcessing(42);
    });

    it('still fiscalises that same cash order once it is completed', function (): void {
        $this->queue->expects('enqueue')->once()->with(42);

        $this->listener->onCompleted(42);
    });

    it('does not defer an online-paid order', function (): void {
        $order = Mockery::mock(\WC_Order::class);
        $order->allows('get_payment_method')->andReturn('stripe');
        Functions\when('wc_get_order')->justReturn($order);
        $this->cashResolver->expects('isCash')->once()->with('stripe')->andReturnFalse();

        $this->queue->expects('enqueue')->once()->with(42);

        $this->listener->onProcessing(42);
    });

    it('never defers payment_complete, whatever the tender is called', function (): void {
        // A gateway that calls payment_complete() has the money in hand. The
        // setting is about cash the courier has not collected yet.
        $this->queue->expects('enqueue')->once()->with(42);

        $this->listener->onPaymentComplete(42);
    });

    it('fiscalises when the order cannot be loaded', function (): void {
        // Fails open on purpose: an extra receipt can be reversed with a
        // refund receipt, one that was never filed cannot be recovered.
        Functions\when('wc_get_order')->justReturn(false);

        $this->queue->expects('enqueue')->once()->with(42);

        $this->listener->onProcessing(42);
    });

    it('ignores a garbage payload without consulting WooCommerce', function (): void {
        $this->queue->expects('enqueue')->never();

        $this->listener->onProcessing('not a number');
    });
});
