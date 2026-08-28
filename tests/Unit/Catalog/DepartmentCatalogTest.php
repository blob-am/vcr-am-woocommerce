<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentLister;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentListerFactory;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Language;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\DepartmentListItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\DepartmentLocalizedTitle;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\TaxRegime;
use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;

beforeEach(function (): void {
    Functions\when('wp_salt')->justReturn(str_repeat('x', 64));
    Functions\when('get_transient')->justReturn(false);
    Functions\when('get_option')->justReturn(null);
    Functions\when('set_transient')->justReturn(true);
});

/**
 * @param array<string, string> $titles language code => title
 */
function makeDepartment(int $id, TaxRegime $regime, array $titles): DepartmentListItem
{
    $localised = [];
    foreach ($titles as $lang => $content) {
        $localised[$lang] = new DepartmentLocalizedTitle(Language::from($lang), $content);
    }

    return new DepartmentListItem(
        internalId: $id,
        externalId: null,
        taxRegime: $regime,
        title: $localised,
    );
}

function makeDepartmentConfig(?string $apiKey): Configuration
{
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn($apiKey);
    $config->allows('baseUrl')->andReturn('https://vcr.am/api/v1');

    return $config;
}

/**
 * @param list<DepartmentListItem> $departments
 */
function makeDepartmentFactory(array $departments): DepartmentListerFactory
{
    $lister = Mockery::mock(DepartmentLister::class);
    $lister->allows('listDepartments')->andReturn($departments);

    $factory = Mockery::mock(DepartmentListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    return $factory;
}

it('returns an empty list when credentials are not configured', function (): void {
    $factory = Mockery::mock(DepartmentListerFactory::class);
    $factory->expects('create')->never();

    $catalog = new DepartmentCatalog(makeDepartmentConfig(null), $factory);

    expect($catalog->list())->toBe([]);
});

it('returns the cached value verbatim when the transient is hot', function (): void {
    $cached = [1 => 'VAT — Bakery (#1)'];

    Functions\when('get_transient')->justReturn($cached);

    $factory = Mockery::mock(DepartmentListerFactory::class);
    $factory->expects('create')->never();

    $catalog = new DepartmentCatalog(makeDepartmentConfig('test-key'), $factory);

    expect($catalog->list())->toBe($cached);
});

it('refresh deletes the cache transient', function (): void {
    $deleted = false;
    Functions\when('delete_transient')->alias(function (string $key) use (&$deleted): bool {
        if ($key === 'vcr_departments_cache') {
            $deleted = true;
        }

        return true;
    });

    $catalog = new DepartmentCatalog(
        makeDepartmentConfig(null),
        Mockery::mock(DepartmentListerFactory::class),
    );
    $catalog->refresh();

    expect($deleted)->toBeTrue();
});

it('leads every label with the tax regime', function (): void {
    // The reason this class exists. An admin scanning the dropdown has to
    // be able to tell the VAT department from the micro-enterprise one
    // without cross-referencing the VCR dashboard — the id alone carries
    // no meaning, and id 1 is VAT on every register ever provisioned.
    $catalog = new DepartmentCatalog(
        makeDepartmentConfig('test-key'),
        makeDepartmentFactory([
            makeDepartment(1, TaxRegime::Vat, ['hy' => 'Հացաբուլկեղեն']),
            makeDepartment(4, TaxRegime::MicroEnterprise, ['hy' => 'Ծառայություններ']),
        ]),
    );

    expect($catalog->list())->toBe([
        1 => 'VAT — Հացաբուլկեղեն (#1)',
        4 => 'Micro-enterprise — Ծառայություններ (#4)',
    ]);
});

it('names every regime the API can return', function (): void {
    $catalog = new DepartmentCatalog(
        makeDepartmentConfig('test-key'),
        makeDepartmentFactory([
            makeDepartment(1, TaxRegime::Vat, []),
            makeDepartment(2, TaxRegime::VatExempt, []),
            makeDepartment(3, TaxRegime::TurnoverTax, []),
            makeDepartment(4, TaxRegime::MicroEnterprise, []),
        ]),
    );

    expect($catalog->list())->toBe([
        1 => 'VAT (#1)',
        2 => 'VAT-exempt (#2)',
        3 => 'Turnover tax (#3)',
        4 => 'Micro-enterprise (#4)',
    ]);
});

it('falls back to a non-Armenian title, then to the id, rather than inventing one', function (): void {
    // Departments created before titles were mandatory come back with an
    // empty map. The regime still has to render — that's the part the
    // admin is choosing on.
    $catalog = new DepartmentCatalog(
        makeDepartmentConfig('test-key'),
        makeDepartmentFactory([
            makeDepartment(7, TaxRegime::TurnoverTax, ['en' => 'Services']),
            makeDepartment(9, TaxRegime::VatExempt, []),
        ]),
    );

    expect($catalog->list())->toBe([
        7 => 'Turnover tax — Services (#7)',
        9 => 'VAT-exempt (#9)',
    ]);
});

it('collapses an API failure to an empty list without caching it', function (): void {
    Functions\when('set_transient')->alias(function (): bool {
        throw new RuntimeException('a failed fetch must not be cached');
    });

    $lister = Mockery::mock(DepartmentLister::class);
    $lister->allows('listDepartments')->andThrow(new RuntimeException('network down'));

    $factory = Mockery::mock(DepartmentListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $catalog = new DepartmentCatalog(makeDepartmentConfig('test-key'), $factory);

    // The settings page must still render — a dropdown that can't load is
    // an inconvenience, an admin page that fatals is an outage.
    expect($catalog->list())->toBe([]);
});
