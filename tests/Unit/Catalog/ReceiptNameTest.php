<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\ReceiptName;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    // No override stored unless a test says otherwise.
    Functions\when('get_post_meta')->justReturn('');
});

function namedProduct(string $name, int $id = 7): WC_Product
{
    $product = Mockery::mock(WC_Product::class);
    $product->allows('get_id')->andReturns($id);
    $product->allows('get_name')->andReturns($name);

    return $product;
}

it('prints the product name when nothing overrides it', function (): void {
    expect((new ReceiptName())->forProduct(namedProduct('Chemex 6 cup')))
        ->toBe('Chemex 6 cup');
});

it('prefers the override stored on the product', function (): void {
    Functions\when('get_post_meta')->justReturn('Chemex 6 cup');

    expect((new ReceiptName())->forProduct(namedProduct('Chemex Classic Series Coffeemaker, 6 cup, glass')))
        ->toBe('Chemex 6 cup');
});

it('collapses the whitespace a pasted title carries', function (): void {
    expect((new ReceiptName())->forProduct(namedProduct("  Coffee \t\n  beans 250g ")))
        ->toBe('Coffee beans 250g');
});

it('accepts a name that fills the line exactly', function (): void {
    $name = str_repeat('a', ReceiptName::MAX_LENGTH);

    expect((new ReceiptName())->forProduct(namedProduct($name)))->toBe($name);
});

it('refuses a name one character too long, and says by how much', function (): void {
    $name = str_repeat('a', ReceiptName::MAX_LENGTH + 1);

    expect(fn (): string => (new ReceiptName())->forProduct(namedProduct($name)))
        ->toThrow(FiscalBuildException::class, 'is 51 characters; a receipt line holds 50');
});

it('counts characters, not bytes, so an Armenian name is not refused for its encoding', function (): void {
    // 40 Armenian characters: 80 bytes in UTF-8, well over the cap if counted wrong.
    $name = str_repeat('ա', 40);

    expect((new ReceiptName())->forProduct(namedProduct($name)))->toBe($name);
});

it('refuses a character the receipt line cannot carry, and names it', function (): void {
    expect(fn (): string => (new ReceiptName())->forProduct(namedProduct('Cake ½ kg')))
        ->toThrow(FiscalBuildException::class, 'U+00BD');
});

it('refuses a product with nothing to print', function (): void {
    expect(fn (): string => (new ReceiptName())->forProduct(namedProduct('   ', 42)))
        ->toThrow(FiscalBuildException::class, 'Product #42 has no name');
});

it('reads no override from a product whose meta is not a string', function (): void {
    Functions\when('get_post_meta')->justReturn(['unexpected']);

    expect((new ReceiptName())->override(namedProduct('Chemex 6 cup')))->toBeNull();
});
