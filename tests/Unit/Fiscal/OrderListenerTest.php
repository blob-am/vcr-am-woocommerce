<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalQueue;
use BlobSolutions\WooCommerceVcrAm\Fiscal\OrderListener;
use Brain\Monkey\Actions;
use Mockery;

beforeEach(function (): void {
    $this->queue = Mockery::mock(FiscalQueue::class);
    $this->listener = new OrderListener($this->queue);
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

it('processing/completed handler also forwards correctly', function (): void {
    $this->queue->expects('enqueue')->once()->with(7);

    $this->listener->onProcessingOrCompleted(7);
});
