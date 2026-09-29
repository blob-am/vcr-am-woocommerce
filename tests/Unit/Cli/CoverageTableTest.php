<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\Report;
use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\StoreSku;
use BlobSolutions\WooCommerceVcrAm\Cli\CoverageTable;

/**
 * The problem slugs and the column order are a contract: `--format=json`
 * output gets parsed by whatever a host schedules, so a rename here breaks
 * somebody's script, and a translated slug breaks it in one locale only.
 */
it('names each finding with a stable, untranslated slug', function (): void {
    $report = Report::of(
        withoutSku: [new StoreSku('', 'Mystery Mug', 12)],
        missing: [new StoreSku('tea', 'Tea', 11)],
        archived: [new StoreSku('coffee', 'Coffee', 10)],
        unverified: [new StoreSku('cocoa', 'Cocoa', 13)],
        orphanOffers: ['walk-in-pastry'],
        checkedCount: 4,
        coveredCount: 0,
        catalogTruncated: true,
    );

    $rows = (new CoverageTable())->rows($report);

    expect(array_column($rows, 'problem'))
        ->toBe(['no-sku', 'no-offer', 'archived-offer', 'unverified', 'orphan-offer']);
});

it('puts every row in the declared column order and nothing else', function (): void {
    $report = Report::of(
        withoutSku: [],
        missing: [new StoreSku('tea', 'Tea', 11)],
        archived: [],
        unverified: [],
        orphanOffers: [],
        checkedCount: 1,
        coveredCount: 0,
        catalogTruncated: false,
    );

    $rows = (new CoverageTable())->rows($report);

    expect(array_keys($rows[0]))->toBe(CoverageTable::COLUMNS)
        ->and($rows[0])->toBe([
            'problem' => 'no-offer',
            'sku' => 'tea',
            'product' => 'Tea',
            'id' => '11',
        ]);
});

it('leaves the product columns empty for a setting-derived SKU, which has no product', function (): void {
    $report = Report::of(
        withoutSku: [],
        missing: [new StoreSku('delivery', 'Shipping line (from settings)')],
        archived: [],
        unverified: [],
        orphanOffers: [],
        checkedCount: 1,
        coveredCount: 0,
        catalogTruncated: false,
    );

    $rows = (new CoverageTable())->rows($report);

    expect($rows[0]['id'])->toBe('')
        ->and($rows[0]['product'])->toBe('Shipping line (from settings)');
});

it('describes an orphan by its external id, because there is no local row to name', function (): void {
    $report = Report::of(
        withoutSku: [],
        missing: [],
        archived: [],
        unverified: [],
        orphanOffers: ['walk-in-pastry'],
        checkedCount: 0,
        coveredCount: 0,
        catalogTruncated: false,
    );

    $rows = (new CoverageTable())->rows($report);

    expect($rows)->toBe([[
        'problem' => 'orphan-offer',
        'sku' => 'walk-in-pastry',
        'product' => '',
        'id' => '',
    ]]);
});

it('returns no rows for a clean store', function (): void {
    $report = Report::of(
        withoutSku: [],
        missing: [],
        archived: [],
        unverified: [],
        orphanOffers: [],
        checkedCount: 12,
        coveredCount: 12,
        catalogTruncated: false,
    );

    expect((new CoverageTable())->rows($report))->toBe([]);
});
