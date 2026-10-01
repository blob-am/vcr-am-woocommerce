<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\OfferBinding;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferLister;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferListerFactory;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferRenamer;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferRenamerFactory;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferTitleSync;
use BlobSolutions\WooCommerceVcrAm\Catalog\ReceiptName;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Language;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\LocalizationEntry;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\OfferDefaultDepartment;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\OfferListItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\OfferType;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    $this->config = Mockery::mock(Configuration::class);
    $this->config->allows('apiKey')->andReturns('key');

    $this->lister = Mockery::mock(OfferLister::class);
    $this->listerFactory = Mockery::mock(OfferListerFactory::class);
    $this->listerFactory->allows('create')->andReturns($this->lister);

    $this->renamer = Mockery::mock(OfferRenamer::class);
    $this->renamerFactory = Mockery::mock(OfferRenamerFactory::class);
    $this->renamerFactory->allows('create')->andReturns($this->renamer);
});

function sync(): OfferTitleSync
{
    return new OfferTitleSync(
        configuration: test()->config,
        listerFactory: test()->listerFactory,
        renamerFactory: test()->renamerFactory,
        receiptName: new ReceiptName(),
    );
}

/**
 * The product the sync will look up, plus the meta it reads: the binding on
 * `OfferBinding::META_KEY` and the receipt-name override on
 * `ReceiptName::META_KEY`.
 *
 * @param array<string, string> $meta
 */
function syncedProduct(string $name, array $meta, int $id = 1423, int $parentId = 0): void
{
    $product = Mockery::mock(WC_Product::class);
    $product->allows('get_id')->andReturns($id);
    $product->allows('get_name')->andReturns($name);
    $product->allows('get_parent_id')->andReturns($parentId);

    Functions\when('wc_get_product')->justReturn($product);
    Functions\when('get_post_meta')->alias(
        static fn (int $postId, string $key, bool $single = false): string => $meta[$key] ?? '',
    );
}

/**
 * @param list<LocalizationEntry> $title
 */
function catalogOffer(array $title, ?string $archivedAt = null, string $externalId = 'wc-1423'): OfferListItem
{
    return new OfferListItem(
        id: 99,
        externalId: $externalId,
        type: OfferType::Product,
        classifierCode: '56.10',
        defaultMeasureUnit: 'pc',
        defaultDepartment: new OfferDefaultDepartment(1),
        title: $title,
        archivedAt: $archivedAt,
        createdAt: '2026-09-01T00:00:00.000Z',
    );
}

function universalTitle(string $content): array
{
    return [new LocalizationEntry(id: 5, language: Language::Multi, content: $content)];
}

it('renames the catalog item when the receipt name no longer matches it', function (): void {
    syncedProduct('Chemex Classic Series Coffeemaker, 6 cup', [
        OfferBinding::META_KEY => 'wc-1423',
        ReceiptName::META_KEY => 'Chemex 6 cup',
    ]);
    $this->lister->allows('listOffers')->with('wc-1423')->andReturns([
        catalogOffer(universalTitle('Chemex Classic Series Coffeemaker')),
    ]);

    $this->renamer->expects('rename')->once()->with(99, 'Chemex 6 cup');

    expect(sync()->run(1423))->toBeFalse();
});

it('writes nothing when the register already holds that name', function (): void {
    syncedProduct('Chemex 6 cup', [OfferBinding::META_KEY => 'wc-1423']);
    $this->lister->allows('listOffers')->andReturns([catalogOffer(universalTitle('Chemex 6 cup'))]);

    $this->renamer->expects('rename')->never();

    expect(sync()->run(1423))->toBeFalse();
});

it('syncs the product name back once the override is cleared', function (): void {
    // No ReceiptName::META_KEY -- the box was emptied, so the product's own
    // name is the answer and the register still holds the old override.
    syncedProduct('Chemex 6 cup', [OfferBinding::META_KEY => 'wc-1423']);
    $this->lister->allows('listOffers')->andReturns([catalogOffer(universalTitle('Chemex short'))]);

    $this->renamer->expects('rename')->once()->with(99, 'Chemex 6 cup');

    sync()->run(1423);
});

it('leaves a title localised in VCR alone, because a rename would flatten it', function (): void {
    syncedProduct('Chemex 6 cup', [OfferBinding::META_KEY => 'wc-1423']);
    $this->lister->allows('listOffers')->andReturns([
        catalogOffer([
            new LocalizationEntry(id: 1, language: Language::Armenian, content: 'Չեմեքս'),
            new LocalizationEntry(id: 2, language: Language::Russian, content: 'Кемекс'),
            new LocalizationEntry(id: 3, language: Language::English, content: 'Chemex'),
        ]),
    ]);

    $this->renamer->expects('rename')->never();

    expect(sync()->run(1423))->toBeFalse();
});

it('does not resurrect an archived catalog item', function (): void {
    syncedProduct('Chemex 6 cup', [OfferBinding::META_KEY => 'wc-1423']);
    $this->lister->allows('listOffers')->andReturns([
        catalogOffer(universalTitle('Chemex old'), archivedAt: '2026-09-15T08:12:42.166Z'),
    ]);

    $this->renamer->expects('rename')->never();

    expect(sync()->run(1423))->toBeFalse();
});

it('does nothing for a product the register has never seen', function (): void {
    syncedProduct('Chemex 6 cup', []);

    $this->lister->expects('listOffers')->never();
    $this->renamer->expects('rename')->never();

    expect(sync()->run(1423))->toBeFalse();
});

it('does not sync a name that cannot go on a receipt line', function (): void {
    syncedProduct(str_repeat('a', ReceiptName::MAX_LENGTH + 1), [OfferBinding::META_KEY => 'wc-1423']);

    $this->lister->expects('listOffers')->never();
    $this->renamer->expects('rename')->never();

    expect(sync()->run(1423))->toBeFalse();
});

it('asks to be retried when the catalog cannot be read', function (): void {
    syncedProduct('Chemex 6 cup', [OfferBinding::META_KEY => 'wc-1423']);
    $this->lister->allows('listOffers')->andThrow(new RuntimeException('connection reset'));

    $this->renamer->expects('rename')->never();

    expect(sync()->run(1423))->toBeTrue();
});

it('asks to be retried when the rename itself fails', function (): void {
    syncedProduct('Chemex 6 cup', [OfferBinding::META_KEY => 'wc-1423']);
    $this->lister->allows('listOffers')->andReturns([catalogOffer(universalTitle('Chemex old'))]);
    $this->renamer->allows('rename')->andThrow(new RuntimeException('503'));

    expect(sync()->run(1423))->toBeTrue();
});

it('does nothing without an API key, rather than failing a background action', function (): void {
    $this->config = Mockery::mock(Configuration::class);
    $this->config->allows('apiKey')->andReturns(null);
    syncedProduct('Chemex 6 cup', [OfferBinding::META_KEY => 'wc-1423']);

    $this->lister->expects('listOffers')->never();

    expect(sync()->run(1423))->toBeFalse();
});

it('ignores a product that no longer exists', function (): void {
    Functions\when('wc_get_product')->justReturn(false);

    $this->lister->expects('listOffers')->never();

    expect(sync()->run(1423))->toBeFalse();
});

it('uses the parent receipt name for a variation that inherits one', function (): void {
    $variation = Mockery::mock(WC_Product::class);
    $variation->allows('get_id')->andReturns(1424);
    $variation->allows('get_name')->andReturns('Chemex 6 cup - Black');
    $variation->allows('get_parent_id')->andReturns(1423);

    Functions\when('wc_get_product')->justReturn($variation);
    Functions\when('get_post_meta')->alias(
        static function (int $postId, string $key, bool $single = false): string {
            if ($postId === 1424 && $key === OfferBinding::META_KEY) {
                return 'wc-1424';
            }
            if ($postId === 1423 && $key === ReceiptName::META_KEY) {
                return 'Chemex 6 cup';
            }

            return '';
        },
    );
    $this->lister->allows('listOffers')->with('wc-1424')->andReturns([
        catalogOffer(universalTitle('Chemex 6 cup - Black'), externalId: 'wc-1424'),
    ]);

    $this->renamer->expects('rename')->once()->with(99, 'Chemex 6 cup');

    sync()->run(1424);
});
