<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\StoreSkuReader;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use Brain\Monkey\Functions;

function productDouble(int $id, string $sku, string $name, string $type = 'simple'): WC_Product
{
    $product = Mockery::mock(WC_Product::class);
    $product->allows('get_id')->andReturn($id);
    $product->allows('get_sku')->andReturn($sku);
    $product->allows('get_name')->andReturn($name);
    $product->allows('get_type')->andReturn($type);

    return $product;
}

/**
 * @param list<int> $childIds
 */
function variableProductDouble(int $id, array $childIds): WC_Product_Variable
{
    $product = Mockery::mock(WC_Product_Variable::class);
    $product->allows('get_id')->andReturn($id);
    $product->allows('get_sku')->andReturn('parent-sku');
    $product->allows('get_name')->andReturn('Shirt');
    $product->allows('get_type')->andReturn('variable');
    $product->allows('get_children')->andReturn($childIds);

    return $product;
}

function readerWith(?string $shippingSku = null, ?string $feeSku = null): StoreSkuReader
{
    $config = Mockery::mock(Configuration::class);
    $config->allows('shippingSku')->andReturn($shippingSku);
    $config->allows('feeSku')->andReturn($feeSku);

    return new StoreSkuReader($config);
}

/**
 * @param list<list<WC_Product>> $pages
 */
function stubProductPages(array $pages): void
{
    Functions\when('wc_get_products')->alias(function (array $args) use ($pages): array {
        $page = is_int($args['page'] ?? null) ? $args['page'] : 1;

        return $pages[$page - 1] ?? [];
    });
}

it('reads a simple product as one reference, trimmed the way the receipt line will be', function (): void {
    stubProductPages([[productDouble(10, '  coffee  ', 'Coffee')]]);

    $references = readerWith()->read();

    expect($references)->toHaveCount(1)
        ->and($references[0]->sku)->toBe('coffee')
        ->and($references[0]->label)->toBe('Coffee')
        ->and($references[0]->productId)->toBe(10);
});

it('keeps an empty SKU as an empty SKU, because that is the finding', function (): void {
    stubProductPages([[productDouble(11, '', 'Mystery Mug')]]);

    $references = readerWith()->read();

    expect($references)->toHaveCount(1)
        ->and($references[0]->sku)->toBe('')
        ->and($references[0]->label)->toBe('Mystery Mug');
});

it('reads a variable product as its variations, never as itself', function (): void {
    stubProductPages([[variableProductDouble(20, [21, 22])]]);
    Functions\when('wc_get_product')->alias(fn (int $id): WC_Product => productDouble(
        $id,
        'shirt-' . $id,
        'Shirt - ' . $id,
        'variation',
    ));

    $references = readerWith()->read();

    expect($references)->toHaveCount(2)
        ->and(array_map(fn ($r) => $r->sku, $references))->toBe(['shirt-21', 'shirt-22'])
        ->and(array_map(fn ($r) => $r->sku, $references))->not->toContain('parent-sku');
});

it('skips a grouped or external product, since neither is ever an order line', function (): void {
    stubProductPages([[
        productDouble(30, 'bundle', 'Gift bundle', 'grouped'),
        productDouble(31, 'affiliate', 'Sold elsewhere', 'external'),
        productDouble(32, 'coffee', 'Coffee'),
    ]]);

    $references = readerWith()->read();

    expect($references)->toHaveCount(1)
        ->and($references[0]->sku)->toBe('coffee');
});

it('includes the shipping and fee SKUs, which are offers with no product behind them', function (): void {
    stubProductPages([[]]);

    $references = readerWith(shippingSku: 'delivery', feeSku: 'handling')->read();

    expect(array_map(fn ($r) => $r->sku, $references))->toBe(['delivery', 'handling'])
        ->and($references[0]->productId)->toBeNull();
});

it('ignores whatever is not a product, rather than trusting the query', function (): void {
    stubProductPages([[productDouble(40, 'coffee', 'Coffee'), 'not-a-product', null]]);

    expect(readerWith()->read())->toHaveCount(1);
});

it('stops paging on a short page instead of querying forever', function (): void {
    $calls = 0;
    Functions\when('wc_get_products')->alias(function (array $args) use (&$calls): array {
        $calls++;

        return $args['page'] === 1 ? [productDouble(50, 'coffee', 'Coffee')] : [];
    });

    readerWith()->read();

    expect($calls)->toBe(1);
});
