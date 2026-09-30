<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\OfferIdentity;

function identityProduct(int $id, string $sku): WC_Product
{
    $product = Mockery::mock(WC_Product::class);
    $product->allows('get_id')->andReturns($id);
    $product->allows('get_sku')->andReturns($sku);

    return $product;
}

it('mints an id from the product id, which WordPress never recycles', function (): void {
    expect(OfferIdentity::mint(identityProduct(1423, 'SHIRT-1')))->toBe('wc-1423');
});

it('offers a clean SKU for adoption', function (): void {
    expect(OfferIdentity::adoptableSku(identityProduct(1, 'SHIRT-1.v2_x')))->toBe('SHIRT-1.v2_x');
});

it('trims a SKU before judging it', function (): void {
    expect(OfferIdentity::adoptableSku(identityProduct(1, "  SHIRT-1\n")))->toBe('SHIRT-1');
});

it('refuses a SKU the API would reject as an external id', function (string $sku): void {
    expect(OfferIdentity::adoptableSku(identityProduct(1, $sku)))->toBeNull();
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'space inside' => ['SHIRT 1'],
    'slash' => ['SHIRT/1'],
    'cyrillic' => ['ФУТБОЛКА-1'],
    'over 255' => [str_repeat('a', 256)],
]);
