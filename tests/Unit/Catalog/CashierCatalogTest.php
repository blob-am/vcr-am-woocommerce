<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\CashierLister;
use BlobSolutions\WooCommerceVcrAm\Catalog\CashierListerFactory;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Language;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\CashierListItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\CashierLocalizedName;
use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;

beforeEach(function (): void {
    Functions\when('wp_salt')->justReturn(str_repeat('x', 64));
    // Default: no transient cached, no API key configured. Individual
    // tests override as needed.
    Functions\when('get_transient')->justReturn(false);
    Functions\when('get_option')->justReturn(null);
});

/**
 * Build a CashierListItem with the localised names provided keyed by
 * language code. Mirrors what the SDK's response decoder produces.
 *
 * @param array<string, string> $names language code => display name
 */
function makeCashier(int $id, string $deskId, array $names): CashierListItem
{
    $localised = [];
    foreach ($names as $lang => $content) {
        $localised[$lang] = new CashierLocalizedName(Language::from($lang), $content);
    }

    return new CashierListItem(deskId: $deskId, internalId: $id, name: $localised);
}

/**
 * Build a Configuration whose `apiKey()` returns the given value via a
 * mocked KeyStore. The keystore option name is irrelevant — we control
 * the read via Brain Monkey's `get_option` stub above.
 */
function makeConfigWithApiKey(?string $apiKey): Configuration
{
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn($apiKey);
    $config->allows('baseUrl')->andReturn('https://vcr.am/api/v1');

    return $config;
}

it('returns an empty list when credentials are not configured', function (): void {
    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->expects('create')->never();

    $catalog = new CashierCatalog(makeConfigWithApiKey(null), $factory);

    expect($catalog->list())->toBe([]);
});

it('returns the cached value verbatim when the transient is hot', function (): void {
    $cached = [1 => 'Alice (desk ABC123)', 2 => 'Bob (desk XYZ789)'];

    Functions\when('get_transient')->justReturn($cached);

    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->expects('create')->never();

    $catalog = new CashierCatalog(makeConfigWithApiKey('test-key'), $factory);

    expect($catalog->list())->toBe($cached);
});

it('refresh deletes the cache transient', function (): void {
    $deleted = false;
    Functions\when('delete_transient')->alias(function (string $key) use (&$deleted): bool {
        if ($key === 'vcr_cashiers_cache') {
            $deleted = true;
        }

        return true;
    });

    $catalog = new CashierCatalog(
        makeConfigWithApiKey(null),
        Mockery::mock(CashierListerFactory::class),
    );
    $catalog->refresh();

    expect($deleted)->toBeTrue();
});

it('fetches from the API when no transient is cached and stores the result', function (): void {
    $stored = null;
    Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl) use (&$stored): bool {
        $stored = $value;

        return true;
    });

    $lister = Mockery::mock(CashierLister::class);
    $lister->expects('listCashiers')->andReturn([
        makeCashier(1, 'A1', ['hy' => 'Անի', 'ru' => 'Ани', 'en' => 'Ani']),
        makeCashier(2, 'B2', ['hy' => 'Բակո', 'ru' => 'Бако', 'en' => 'Bako']),
    ]);

    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->expects('create')->with('test-key')->andReturn($lister);

    $catalog = new CashierCatalog(makeConfigWithApiKey('test-key'), $factory);
    $shaped = $catalog->list();

    expect($shaped)->toBe([
        1 => 'Անի (desk A1)',
        2 => 'Բակո (desk B2)',
    ]);
    expect($stored)->toBe($shaped);
});

it('falls back to the first available language when Armenian name is missing', function (): void {
    Functions\when('set_transient')->justReturn(true);

    $lister = Mockery::mock(CashierLister::class);
    $lister->expects('listCashiers')->andReturn([
        makeCashier(7, 'Z9', ['ru' => 'Иван', 'en' => 'Ivan']),
    ]);

    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $catalog = new CashierCatalog(makeConfigWithApiKey('k'), $factory);

    // First language available is Russian — falls back to it.
    expect($catalog->list())->toBe([7 => 'Иван (desk Z9)']);
});

it('uses the bare internal id when no localised name exists at all', function (): void {
    Functions\when('set_transient')->justReturn(true);

    $lister = Mockery::mock(CashierLister::class);
    $lister->expects('listCashiers')->andReturn([
        makeCashier(42, 'NONAME', []),
    ]);

    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $catalog = new CashierCatalog(makeConfigWithApiKey('k'), $factory);

    expect($catalog->list())->toBe([42 => '#42 (desk NONAME)']);
});

it('returns an empty list and does NOT cache when the API call throws', function (): void {
    $cacheWritten = false;
    Functions\when('set_transient')->alias(function () use (&$cacheWritten): bool {
        $cacheWritten = true;

        return true;
    });

    $lister = Mockery::mock(CashierLister::class);
    $lister->expects('listCashiers')->andThrow(new RuntimeException('boom'));

    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->expects('create')->andReturn($lister);

    $catalog = new CashierCatalog(makeConfigWithApiKey('k'), $factory);

    expect($catalog->list())->toBe([])
        ->and($cacheWritten)->toBeFalse();
});

it('returns an empty list without caching when the API succeeds with zero cashiers', function (): void {
    $cacheWritten = false;
    Functions\when('set_transient')->alias(function () use (&$cacheWritten): bool {
        $cacheWritten = true;

        return true;
    });

    $lister = Mockery::mock(CashierLister::class);
    $lister->expects('listCashiers')->andReturn([]);

    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->expects('create')->andReturn($lister);

    $catalog = new CashierCatalog(makeConfigWithApiKey('k'), $factory);

    expect($catalog->list())->toBe([])
        // Empty list deliberately NOT cached. The bootstrap workflow
        // (admin saves key, then creates first cashier in VCR, then
        // returns to settings) breaks if we cache empty for an hour —
        // the dropdown stays empty until admin guesses to re-save.
        ->and($cacheWritten)->toBeFalse();
});
