<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\CatalogListing;
use BlobSolutions\WooCommerceVcrAm\Catalog\CatalogPolicy;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferBinding;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferLister;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferListerFactory;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\Exception\FiscalBuildException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\OfferDefaultDepartment;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\OfferListItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\OfferType;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('get_post_meta')->justReturn('');
    Functions\when('update_post_meta')->justReturn(true);
    Functions\when('get_option')->justReturn([]);
    Functions\when('update_option')->justReturn(true);

    $this->config = Mockery::mock(Configuration::class);
    $this->config->allows('apiKey')->andReturns('key');

    $this->lister = Mockery::mock(OfferLister::class);
    $this->listerFactory = Mockery::mock(OfferListerFactory::class);
    $this->listerFactory->allows('create')->andReturns($this->lister);

    $this->departments = Mockery::mock(DepartmentCatalog::class);
    $this->departments->allows('list')->andReturns(CatalogListing::of([3 => 'VAT']));

    $this->armed = new CatalogPolicy(classifierCode: '56.10', departmentInternalId: 4);
});

function bindingProduct(int $id = 1423, string $sku = 'SHIRT-1', string $name = 'Chemex 6 cup'): WC_Product
{
    $product = Mockery::mock(WC_Product::class);
    $product->allows('get_id')->andReturns($id);
    $product->allows('get_sku')->andReturns($sku);
    $product->allows('get_name')->andReturns($name);
    $product->allows('is_virtual')->andReturns(false);
    $product->allows('is_downloadable')->andReturns(false);

    return $product;
}

function liveOffer(string $externalId, ?string $archivedAt = null): OfferListItem
{
    return new OfferListItem(
        id: 1,
        externalId: $externalId,
        type: OfferType::Product,
        classifierCode: '56.10',
        defaultMeasureUnit: 'pc',
        defaultDepartment: new OfferDefaultDepartment(1),
        title: [],
        archivedAt: $archivedAt,
        createdAt: '2026-09-30T00:00:00.000Z',
    );
}

/**
 * Records what was written, rather than asserting through Brain Monkey: a
 * `when()` stub declared in beforeEach wins over a later `expect()`, which
 * quietly turns such an assertion into "called 0 times".
 */
function recordMetaWrites(): object
{
    $recorder = new class () {
        /** @var list<array{int, string, mixed}> */
        public array $calls = [];
    };

    Functions\when('update_post_meta')->alias(
        function (int $id, string $key, mixed $value) use ($recorder): bool {
            $recorder->calls[] = [$id, $key, $value];

            return true;
        },
    );

    return $recorder;
}

function recordOptionWrites(): object
{
    $recorder = new class () {
        /** @var list<array{string, mixed}> */
        public array $calls = [];
    };

    Functions\when('update_option')->alias(
        function (string $key, mixed $value) use ($recorder): bool {
            $recorder->calls[] = [$key, $value];

            return true;
        },
    );

    return $recorder;
}

function binding(): OfferBinding
{
    return new OfferBinding(
        test()->config,
        test()->listerFactory,
        test()->departments,
    );
}

it('references the id a product is already bound to, without asking the API', function (): void {
    Functions\when('get_post_meta')->justReturn('wc-1423');
    $this->lister->expects('listOffers')->never();

    $offer = binding()->forProduct(bindingProduct(), $this->armed);

    expect($offer->externalId)->toBe('wc-1423')
        ->and($offer->isNew())->toBeFalse();
});

it('adopts an offer the register already holds under the product SKU', function (): void {
    // The catalogue someone built by hand, with their own classifier code on
    // it. Describing a second offer beside it would both duplicate the row and
    // overrule the code they chose.
    $this->lister->expects('listOffers')->with('SHIRT-1')->andReturns([liveOffer('SHIRT-1')]);
    $written = recordMetaWrites();

    $offer = binding()->forProduct(bindingProduct(), $this->armed);

    expect($offer->externalId)->toBe('SHIRT-1')
        ->and($offer->isNew())->toBeFalse()
        ->and($written->calls)->toBe([[1423, OfferBinding::META_KEY, 'SHIRT-1']]);
});

it('does not adopt an archived offer', function (): void {
    $this->lister->expects('listOffers')->with('SHIRT-1')->andReturns([liveOffer('SHIRT-1', '2026-09-01T00:00:00.000Z')]);

    $offer = binding()->forProduct(bindingProduct(), $this->armed);

    expect($offer->externalId)->toBe('wc-1423')
        ->and($offer->isNew())->toBeTrue();
});

it('describes a new offer under a minted id when the register has nothing', function (): void {
    $this->lister->expects('listOffers')->with('SHIRT-1')->andReturns([]);

    $offer = binding()->forProduct(bindingProduct(), $this->armed);

    expect($offer->isNew())->toBeTrue()
        ->and($offer->externalId)->toBe('wc-1423')
        ->and($offer->classifierCode)->toBe('56.10')
        ->and($offer->type)->toBe(OfferType::Product)
        ->and($offer->defaultDepartment?->id)->toBe(4)
        ->and($offer->title?->content)->toBe('Chemex 6 cup');
});

it('skips the lookup entirely for a product whose SKU could never be an external id', function (): void {
    $this->lister->expects('listOffers')->never();

    $offer = binding()->forProduct(bindingProduct(sku: 'ФУТБОЛКА 1'), $this->armed);

    expect($offer->externalId)->toBe('wc-1423')
        ->and($offer->isNew())->toBeTrue();
});

it('calls a downloadable product a service', function (): void {
    $product = Mockery::mock(WC_Product::class);
    $product->allows('get_id')->andReturns(55);
    $product->allows('get_sku')->andReturns('');
    $product->allows('get_name')->andReturns('Gift card PDF');
    $product->allows('is_virtual')->andReturns(true);
    $product->allows('is_downloadable')->andReturns(true);

    $offer = binding()->forProduct($product, $this->armed);

    expect($offer->type)->toBe(OfferType::Service)
        ->and($offer->externalId)->toBe('wc-55');
});

it('writes no meta until a sale carrying the description succeeds', function (): void {
    $this->lister->allows('listOffers')->andReturns([]);
    $written = recordMetaWrites();

    binding()->forProduct(bindingProduct(), $this->armed);

    expect($written->calls)->toBe([]);
});

it('remembers the minted id once the sale is confirmed', function (): void {
    $this->lister->allows('listOffers')->andReturns([]);
    $written = recordMetaWrites();

    $binding = binding();
    $binding->forProduct(bindingProduct(), $this->armed);
    $binding->confirm();

    expect($written->calls)->toBe([[1423, OfferBinding::META_KEY, 'wc-1423']]);
});

it('refuses a product the register lacks when it may not create one', function (): void {
    $this->lister->expects('listOffers')->with('SHIRT-1')->andReturns([]);

    expect(fn () => binding()->forProduct(bindingProduct(), new CatalogPolicy()))
        ->toThrow(FiscalBuildException::class, 'looked for SKU "SHIRT-1"');
});

it('refuses rather than minting a duplicate when the catalogue cannot be read', function (): void {
    // A minted id here would sit beside an offer that does exist, and the
    // merchant would have two rows for one product with no way to tell why.
    $this->lister->expects('listOffers')->andThrow(new RuntimeException('connection reset'));

    expect(fn () => binding()->forProduct(bindingProduct(), $this->armed))
        ->toThrow(FiscalBuildException::class, 'Could not read');
});

it('uses the only department of a register when none is configured', function (): void {
    $this->lister->allows('listOffers')->andReturns([]);
    $policy = new CatalogPolicy(classifierCode: '56.10');

    $offer = binding()->forProduct(bindingProduct(), $policy);

    expect($offer->defaultDepartment?->id)->toBe(3);
});

it('refuses to pick between several departments', function (): void {
    $this->lister->allows('listOffers')->andReturns([]);
    $departments = Mockery::mock(DepartmentCatalog::class);
    $departments->allows('list')->andReturns(CatalogListing::of([1 => 'VAT', 2 => 'Turnover']));
    $binding = new OfferBinding($this->config, $this->listerFactory, $departments);

    expect(fn () => $binding->forProduct(bindingProduct(), new CatalogPolicy(classifierCode: '56.10')))
        ->toThrow(FiscalBuildException::class, 'more than one department');
});

it('prefers a configured shipping SKU over creating its own line', function (): void {
    $policy = new CatalogPolicy(classifierCode: '56.10', departmentInternalId: 4, shippingSku: 'ship-001');

    $offer = binding()->forShipping($policy);

    expect($offer->externalId)->toBe('ship-001')
        ->and($offer->isNew())->toBeFalse();
});

it('describes its own shipping line, then references it once confirmed', function (): void {
    $options = recordOptionWrites();
    $binding = binding();

    $described = $binding->forShipping($this->armed);
    expect($described->isNew())->toBeTrue()
        ->and($described->externalId)->toBe(OfferBinding::SHIPPING_EXTERNAL_ID);

    $binding->confirm();
    expect($options->calls)->toBe([
        [OfferBinding::SYNTHETIC_OPTION, [OfferBinding::SHIPPING_EXTERNAL_ID => true]],
    ]);

    // What the next order sees, now that the register is known to hold it.
    Functions\when('get_option')->justReturn([OfferBinding::SHIPPING_EXTERNAL_ID => true]);
    expect(binding()->forShipping($this->armed)->isNew())->toBeFalse();
});

it('refuses a fee line it may neither reference nor create', function (): void {
    expect(fn () => binding()->forFee(new CatalogPolicy()))
        ->toThrow(FiscalBuildException::class, 'fee lines');
});
