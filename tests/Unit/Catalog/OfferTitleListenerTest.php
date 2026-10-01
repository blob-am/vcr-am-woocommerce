<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Catalog;

use BlobSolutions\WooCommerceVcrAm\Catalog\OfferTitleListener;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferTitleQueue;
use BlobSolutions\WooCommerceVcrAm\Catalog\ReceiptName;
use BlobSolutions\WooCommerceVcrAm\Catalog\ReceiptNameField;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use WC_Product;

beforeEach(function (): void {
    $this->queue = Mockery::mock(OfferTitleQueue::class);
    $this->listener = new OfferTitleListener($this->queue, new ReceiptName());
    Functions\when('get_post_meta')->justReturn('');
    Functions\when('wc_get_product')->justReturn(false);
});

/** @param list<int> $children */
function productWithChildren(array $children): WC_Product
{
    $product = Mockery::mock(WC_Product::class);
    $product->allows('get_children')->andReturns($children);

    return $product;
}

it('listens on the field\'s change action', function (): void {
    Actions\expectAdded(ReceiptNameField::CHANGED_ACTION)->once();

    $this->listener->register();
});

it('queues the product whose box was edited', function (): void {
    $this->queue->expects('enqueue')->once()->with(1423);

    $this->listener->handle(1423);
});

it('queues every variation that prints the parent name', function (): void {
    Functions\when('wc_get_product')->justReturn(productWithChildren([11, 12]));

    $this->queue->expects('enqueue')->once()->with(1423);
    $this->queue->expects('enqueue')->once()->with(11);
    $this->queue->expects('enqueue')->once()->with(12);

    $this->listener->handle(1423);
});

it('leaves a variation with its own name out of it', function (): void {
    Functions\when('wc_get_product')->justReturn(productWithChildren([11, 12]));
    Functions\when('get_post_meta')->alias(
        static fn (int $postId, string $key, bool $single = false): string => $postId === 11 ? 'Its own name' : '',
    );

    $this->queue->expects('enqueue')->once()->with(1423);
    $this->queue->expects('enqueue')->once()->with(12);
    $this->queue->expects('enqueue')->never()->with(11);

    $this->listener->handle(1423);
});

it('ignores a post id that is not one', function (): void {
    $this->queue->expects('enqueue')->never();

    $this->listener->handle('1423');
});
