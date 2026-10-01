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
    // A top-level product; WooCommerce reports 0, not null.
    $product->allows('get_parent_id')->andReturns(0);

    return $product;
}

function namedVariation(string $name, int $id = 8, int $parentId = 7): WC_Product
{
    $variation = Mockery::mock(WC_Product::class);
    $variation->allows('get_id')->andReturns($id);
    $variation->allows('get_name')->andReturns($name);
    $variation->allows('get_parent_id')->andReturns($parentId);

    return $variation;
}

/**
 * `get_post_meta` answers per post id, which is the only way to tell "the
 * variation has its own name" from "it inherits the parent's".
 *
 * @param array<int, mixed> $byPostId
 */
function metaByPostId(array $byPostId): void
{
    Functions\when('get_post_meta')->alias(
        static fn (int $postId, string $key, bool $single = false): mixed => $byPostId[$postId] ?? '',
    );
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

    expect((new ReceiptName())->overrideFor(namedProduct('Chemex 6 cup')))->toBeNull();
});

it('inherits the parent product\'s receipt name when a variation has none of its own', function (): void {
    metaByPostId([7 => 'Chemex 6 cup']);

    expect((new ReceiptName())->forProduct(namedVariation('Chemex Classic Series Coffeemaker, 6 cup, glass - Black')))
        ->toBe('Chemex 6 cup');
});

it('prefers a variation\'s own receipt name over the parent\'s', function (): void {
    metaByPostId([7 => 'Chemex 6 cup', 8 => 'Chemex 6 cup black']);

    expect((new ReceiptName())->forProduct(namedVariation('Chemex Classic Series Coffeemaker, 6 cup, glass - Black')))
        ->toBe('Chemex 6 cup black');
});

it('prints the variation name when neither it nor its parent carries one', function (): void {
    metaByPostId([]);

    expect((new ReceiptName())->forProduct(namedVariation('Chemex 6 cup - Black')))
        ->toBe('Chemex 6 cup - Black');
});

it('never looks for an override on post 0 for a top-level product', function (): void {
    $asked = [];
    Functions\when('get_post_meta')->alias(
        static function (int $postId, string $key, bool $single = false) use (&$asked): string {
            $asked[] = $postId;

            return '';
        },
    );

    (new ReceiptName())->forProduct(namedProduct('Chemex 6 cup'));

    expect($asked)->toBe([7]);
});

it('sends a product to the General tab, which is where the box actually is', function (): void {
    $name = str_repeat('a', ReceiptName::MAX_LENGTH + 1);

    expect(fn (): string => (new ReceiptName())->forProduct(namedProduct($name)))
        ->toThrow(FiscalBuildException::class, 'under Product data -> General');
});

it('tells a variation about both boxes, because either one answers it', function (): void {
    $name = str_repeat('a', ReceiptName::MAX_LENGTH + 1);

    expect(fn (): string => (new ReceiptName())->forProduct(namedVariation($name)))
        ->toThrow(FiscalBuildException::class, 'on this variation under Product data -> Variations');
});

it('never names a panel the plugin does not have', function (): void {
    $tooLong = str_repeat('a', ReceiptName::MAX_LENGTH + 1);

    foreach ([namedProduct($tooLong), namedVariation($tooLong), namedProduct('Cake 1/2 kg' . "\u{00BD}"), namedProduct('  ')] as $product) {
        try {
            (new ReceiptName())->forProduct($product);
            throw new RuntimeException('expected a refusal');
        } catch (FiscalBuildException $e) {
            expect($e->getMessage())->not->toContain('VCR panel');
        }
    }
});
