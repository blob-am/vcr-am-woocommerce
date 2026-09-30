<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\CatalogPolicy;
use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\Checker;
use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\StoreSku;
use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\StoreSkuReader;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferLister;
use BlobSolutions\WooCommerceVcrAm\Catalog\OfferListerFactory;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProblem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrNetworkException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\OfferDefaultDepartment;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\OfferListItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\OfferType;
use BlobSolutions\WooCommerceVcrAm\Vendor\Psr\Http\Message\RequestInterface;

/**
 * Builds an offer the way the API returns one. Only `externalId` and
 * `archivedAt` matter to the coverage check; everything else is filler the
 * SDK's DTO requires.
 */
function offerRow(?string $externalId, ?string $archivedAt = null): OfferListItem
{
    return new OfferListItem(
        id: 1,
        externalId: $externalId,
        type: OfferType::Product,
        classifierCode: '47.91',
        defaultMeasureUnit: 'pc',
        defaultDepartment: new OfferDefaultDepartment(internalId: 1),
        title: [],
        archivedAt: $archivedAt,
        createdAt: '2026-09-01T00:00:00Z',
    );
}

/**
 * @param  list<StoreSku>      $storeSkus
 * @param  list<OfferListItem> $offers
 * @return array{0: Checker, 1: OfferLister&Mockery\MockInterface}
 */
function makeChecker(array $storeSkus, array $offers, ?string $apiKey = 'key-1'): array
{
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn($apiKey);
    $config->allows('catalogPolicy')->andReturn(new CatalogPolicy());

    $lister = Mockery::mock(OfferLister::class);
    $lister->allows('listOffers')->withNoArgs()->andReturn($offers);
    $lister->allows('listOffers')->withNoArgs()->andReturn($offers);

    $factory = Mockery::mock(OfferListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $reader = Mockery::mock(StoreSkuReader::class);
    $reader->allows('read')->andReturn($storeSkus);

    return [new Checker($config, $factory, $reader), $lister];
}

/** @return list<OfferListItem> */
function cappedOffers(): array
{
    $offers = [];
    for ($i = 0; $i < Checker::OFFER_LIST_CAP; $i++) {
        $offers[] = offerRow('filler-' . $i);
    }

    return $offers;
}

// ---------- the plain answers ----------

it('reports whether the plugin may create what it did not find', function (): void {
    // The report carries the answer so nothing downstream has to re-derive it:
    // the same findings mean "go and do this" or "this is about to happen by
    // itself" depending only on this flag.
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn('key-1');
    $config->allows('catalogPolicy')->andReturn(new CatalogPolicy(classifierCode: '56.10'));

    $lister = Mockery::mock(OfferLister::class);
    $lister->allows('listOffers')->withNoArgs()->andReturn([]);
    $lister->allows('listOffers')->andReturn([]);
    $factory = Mockery::mock(OfferListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $reader = Mockery::mock(StoreSkuReader::class);
    $reader->allows('read')->andReturn([new StoreSku('tea', 'Tea', 11)]);

    $report = (new Checker($config, $factory, $reader))->check();

    expect($report->catalogArmed)->toBeTrue()
        ->and($report->missing)->toHaveCount(1)
        ->and($report->hasBlockers())->toBeFalse();
});

it('reports nothing to do when every SKU has a live offer', function (): void {
    [$checker] = makeChecker(
        [new StoreSku('coffee', 'Coffee', 10), new StoreSku('tea', 'Tea', 11)],
        [offerRow('coffee'), offerRow('tea')],
    );

    $report = $checker->check();

    expect($report->isAvailable())->toBeTrue()
        ->and($report->hasBlockers())->toBeFalse()
        ->and($report->coveredCount)->toBe(2)
        ->and($report->checkedCount)->toBe(2)
        ->and($report->catalogTruncated)->toBeFalse();
});

it('names the SKU that has no offer', function (): void {
    [$checker] = makeChecker(
        [new StoreSku('coffee', 'Coffee', 10), new StoreSku('tea', 'Tea', 11)],
        [offerRow('coffee')],
    );

    $report = $checker->check();

    expect($report->missing)->toHaveCount(1)
        ->and($report->missing[0]->sku)->toBe('tea')
        ->and($report->missing[0]->label)->toBe('Tea')
        ->and($report->coveredCount)->toBe(1)
        ->and($report->hasBlockers())->toBeTrue();
});

it('tells an archived offer from a missing one, because the fix differs', function (): void {
    [$checker] = makeChecker(
        [new StoreSku('coffee', 'Coffee', 10), new StoreSku('tea', 'Tea', 11)],
        [offerRow('coffee', '2026-09-01T00:00:00Z')],
    );

    $report = $checker->check();

    expect($report->archived)->toHaveCount(1)
        ->and($report->archived[0]->sku)->toBe('coffee')
        ->and($report->missing)->toHaveCount(1)
        ->and($report->missing[0]->sku)->toBe('tea');
});

it('prefers a live offer over an archived one carrying the same external id', function (): void {
    [$checker] = makeChecker(
        [new StoreSku('coffee', 'Coffee', 10)],
        [offerRow('coffee', '2026-09-01T00:00:00Z'), offerRow('coffee')],
    );

    $report = $checker->check();

    expect($report->coveredCount)->toBe(1)
        ->and($report->archived)->toBe([])
        ->and($report->hasBlockers())->toBeFalse();
});

it('collects a product with no SKU separately, since no offer can help it', function (): void {
    [$checker] = makeChecker(
        [new StoreSku('', 'Mystery Mug', 12), new StoreSku('coffee', 'Coffee', 10)],
        [offerRow('coffee')],
    );

    $report = $checker->check();

    expect($report->withoutSku)->toHaveCount(1)
        ->and($report->withoutSku[0]->label)->toBe('Mystery Mug')
        ->and($report->missing)->toBe([])
        ->and($report->checkedCount)->toBe(1)
        ->and($report->hasBlockers())->toBeTrue();
});

it('counts one SKU once when several variations fall back to it', function (): void {
    [$checker] = makeChecker(
        [
            new StoreSku('shirt', 'Shirt - Small', 20),
            new StoreSku('shirt', 'Shirt - Medium', 21),
            new StoreSku('shirt', 'Shirt - Large', 22),
        ],
        [],
    );

    $report = $checker->check();

    expect($report->checkedCount)->toBe(1)
        ->and($report->missing)->toHaveCount(1)
        ->and($report->missing[0]->label)->toBe('Shirt - Small');
});

it('matches an all-digit SKU, which PHP would otherwise turn into an int key', function (): void {
    [$checker] = makeChecker(
        [new StoreSku('12345', 'Numbered item', 30)],
        [offerRow('12345')],
    );

    $report = $checker->check();

    expect($report->coveredCount)->toBe(1)
        ->and($report->missing)->toBe([]);
});

it('ignores an offer that carries no external id, rather than matching it to nothing', function (): void {
    [$checker] = makeChecker(
        [new StoreSku('coffee', 'Coffee', 10)],
        [offerRow(null), offerRow('')],
    );

    $report = $checker->check();

    expect($report->missing)->toHaveCount(1)
        ->and($report->missing[0]->sku)->toBe('coffee');
});

// ---------- failures ----------

it('asks for an API key before it asks the store anything', function (): void {
    [$checker] = makeChecker([], [], apiKey: null);

    $report = $checker->check();

    expect($report->isAvailable())->toBeFalse()
        ->and($report->failure?->problem)->toBe(ConnectionProblem::NoApiKey)
        ->and($report->hasBlockers())->toBeFalse();
});

it('reports a classified failure instead of an empty catalog', function (): void {
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn('key-1');
    $config->allows('catalogPolicy')->andReturn(new CatalogPolicy());

    $lister = Mockery::mock(OfferLister::class);
    $lister->allows('listOffers')->andThrow(new VcrNetworkException(
        Mockery::mock(RequestInterface::class),
        new RuntimeException('cURL error 7: Failed to connect'),
    ));

    $factory = Mockery::mock(OfferListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $reader = Mockery::mock(StoreSkuReader::class);
    $reader->allows('read')->andReturn([new StoreSku('coffee', 'Coffee', 10)]);

    $report = (new Checker($config, $factory, $reader))->check();

    expect($report->isAvailable())->toBeFalse()
        ->and($report->failure?->problem)->toBe(ConnectionProblem::Unreachable)
        ->and($report->missing)->toBe([]);
});

// ---------- the row cap ----------

it('does not call a SKU missing when the catalog came back capped', function (): void {
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn('key-1');
    $config->allows('catalogPolicy')->andReturn(new CatalogPolicy());

    $lister = Mockery::mock(OfferLister::class);
    $lister->expects('listOffers')->withNoArgs()->andReturn(cappedOffers());
    // The cap makes absence meaningless, so the SKU gets asked about directly.
    $lister->expects('listOffers')->with('coffee')->andReturn([offerRow('coffee')]);

    $factory = Mockery::mock(OfferListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $reader = Mockery::mock(StoreSkuReader::class);
    $reader->allows('read')->andReturn([new StoreSku('coffee', 'Coffee', 10)]);

    $report = (new Checker($config, $factory, $reader))->check();

    expect($report->catalogTruncated)->toBeTrue()
        ->and($report->coveredCount)->toBe(1)
        ->and($report->missing)->toBe([])
        ->and($report->unverified)->toBe([]);
});

it('confirms a SKU really is missing when the exact lookup finds nothing', function (): void {
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn('key-1');
    $config->allows('catalogPolicy')->andReturn(new CatalogPolicy());

    $lister = Mockery::mock(OfferLister::class);
    $lister->expects('listOffers')->withNoArgs()->andReturn(cappedOffers());
    $lister->expects('listOffers')->with('tea')->andReturn([]);

    $factory = Mockery::mock(OfferListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $reader = Mockery::mock(StoreSkuReader::class);
    $reader->allows('read')->andReturn([new StoreSku('tea', 'Tea', 11)]);

    $report = (new Checker($config, $factory, $reader))->check();

    expect($report->missing)->toHaveCount(1)
        ->and($report->missing[0]->sku)->toBe('tea')
        ->and($report->catalogTruncated)->toBeTrue();
});

it('stops spending lookups at the budget and says which SKUs it never decided', function (): void {
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn('key-1');
    $config->allows('catalogPolicy')->andReturn(new CatalogPolicy());

    $overBudget = Checker::MAX_EXACT_LOOKUPS + 5;
    $storeSkus = [];
    for ($i = 0; $i < $overBudget; $i++) {
        $storeSkus[] = new StoreSku('sku-' . $i, 'Product ' . $i, 100 + $i);
    }

    $lister = Mockery::mock(OfferLister::class);
    $lister->expects('listOffers')->withNoArgs()->andReturn(cappedOffers());
    $lister->shouldReceive('listOffers')
        ->times(Checker::MAX_EXACT_LOOKUPS)
        ->with(Mockery::type('string'))
        ->andReturn([]);

    $factory = Mockery::mock(OfferListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $reader = Mockery::mock(StoreSkuReader::class);
    $reader->allows('read')->andReturn($storeSkus);

    $report = (new Checker($config, $factory, $reader))->check();

    expect($report->missing)->toHaveCount(Checker::MAX_EXACT_LOOKUPS)
        ->and($report->unverified)->toHaveCount(5)
        ->and($report->hasBlockers())->toBeTrue();
});

// ---------- orphans ----------

it('says nothing about offers no product claims unless asked', function (): void {
    [$checker] = makeChecker(
        [new StoreSku('coffee', 'Coffee', 10)],
        [offerRow('coffee'), offerRow('walk-in-pastry')],
    );

    expect($checker->check()->orphanOffers)->toBe([])
        ->and($checker->check(includeOrphans: true)->orphanOffers)->toBe(['walk-in-pastry']);
});

it('withholds the orphan list when the catalog was capped, since it cannot be complete', function (): void {
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn('key-1');
    $config->allows('catalogPolicy')->andReturn(new CatalogPolicy());

    $lister = Mockery::mock(OfferLister::class);
    $lister->allows('listOffers')->withNoArgs()->andReturn(cappedOffers());

    $factory = Mockery::mock(OfferListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $reader = Mockery::mock(StoreSkuReader::class);
    $reader->allows('read')->andReturn([]);

    $report = (new Checker($config, $factory, $reader))->check(includeOrphans: true);

    expect($report->catalogTruncated)->toBeTrue()
        ->and($report->orphanOffers)->toBe([]);
});
