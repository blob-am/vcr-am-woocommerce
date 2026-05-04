<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Fiscal\ItemBuilder;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\Department;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Unit;
use Mockery;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;
use WC_Product;

beforeEach(function (): void {
    $this->builder = new ItemBuilder();
    $this->department = new Department(7);
});

/**
 * Helper to mint a WC_Order_Item_Product mock backed by a WC_Product mock,
 * with sensible defaults the test can override per call.
 */
function mockProductLine(string $sku = 'SKU-1', float $qty = 1.0, string $total = '100', string $totalTax = '20'): WC_Order_Item_Product
{
    $product = Mockery::mock(WC_Product::class);
    $product->allows('get_sku')->andReturn($sku);
    $product->allows('get_name')->andReturn('Some Product');

    $item = Mockery::mock(WC_Order_Item_Product::class);
    $item->allows('get_product')->andReturn($product);
    $item->allows('get_quantity')->andReturn($qty);
    $item->allows('get_total')->andReturn($total);
    $item->allows('get_total_tax')->andReturn($totalTax);
    $item->allows('get_name')->andReturn('Some Product');

    return $item;
}

it('converts a single product line into a SaleItem with VAT-inclusive unit price', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_items')->andReturn([mockProductLine(qty: 2.0, total: '200', totalTax: '40')]);

    $items = $this->builder->build($order, $this->department);

    expect($items)->toHaveCount(1)
        ->and($items[0]->offer->externalId)->toBe('SKU-1')
        ->and($items[0]->department->id)->toBe(7)
        ->and($items[0]->quantity)->toBe('2')
        // (200 + 40) / 2 = 120
        ->and($items[0]->price)->toBe('120')
        ->and($items[0]->unit)->toBe(Unit::Piece);
});

it('skips non-product line items (fees, shipping, taxes)', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $shipping = Mockery::mock(WC_Order_Item::class);
    $product = mockProductLine();
    $order->allows('get_items')->andReturn([$shipping, $product]);

    $items = $this->builder->build($order, $this->department);

    expect($items)->toHaveCount(1);
});

it('throws when an order has no fiscalisable lines', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $shipping = Mockery::mock(WC_Order_Item::class);
    $order->allows('get_items')->andReturn([$shipping]);

    $this->builder->build($order, $this->department);
})->throws(FiscalBuildException::class, 'no fiscalisable line items');

it('throws when a product has no SKU', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_items')->andReturn([mockProductLine(sku: '')]);

    $this->builder->build($order, $this->department);
})->throws(FiscalBuildException::class, 'has no SKU');

it('throws when SKU is just whitespace', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_items')->andReturn([mockProductLine(sku: '   ')]);

    $this->builder->build($order, $this->department);
})->throws(FiscalBuildException::class, 'has no SKU');

it('throws when the underlying product is gone', function (): void {
    $item = Mockery::mock(WC_Order_Item_Product::class);
    $item->allows('get_product')->andReturn(false);
    $item->allows('get_name')->andReturn('Ghost');

    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_items')->andReturn([$item]);

    $this->builder->build($order, $this->department);
})->throws(FiscalBuildException::class, 'no longer exists');

it('throws on a zero-quantity line', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_items')->andReturn([mockProductLine(qty: 0.0)]);

    $this->builder->build($order, $this->department);
})->throws(FiscalBuildException::class, 'zero quantity');

it('throws on a negative quantity', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_items')->andReturn([mockProductLine(qty: -1.0)]);

    $this->builder->build($order, $this->department);
})->throws(FiscalBuildException::class, 'Negative line quantity');

it('formats fractional quantities without trailing zeros', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_items')->andReturn([
        mockProductLine(qty: 1.5, total: '150', totalTax: '30'),
    ]);

    $items = $this->builder->build($order, $this->department);

    expect($items[0]->quantity)->toBe('1.5')
        // (150 + 30) / 1.5 = 120
        ->and($items[0]->price)->toBe('120');
});

it('builds multiple lines when the order has several products', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_items')->andReturn([
        mockProductLine(sku: 'SKU-A', qty: 1.0, total: '50', totalTax: '10'),
        mockProductLine(sku: 'SKU-B', qty: 3.0, total: '300', totalTax: '60'),
    ]);

    $items = $this->builder->build($order, $this->department);

    expect($items)->toHaveCount(2)
        ->and($items[0]->offer->externalId)->toBe('SKU-A')
        ->and($items[0]->price)->toBe('60')
        ->and($items[1]->offer->externalId)->toBe('SKU-B')
        ->and($items[1]->price)->toBe('120');
});
