<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\Classifier\Directory;
use BlobSolutions\WooCommerceVcrAm\Catalog\Classifier\Searcher;
use BlobSolutions\WooCommerceVcrAm\Catalog\Classifier\SearcherFactory;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProblem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrNetworkException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Language;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\ClassifierSearchItem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\OfferType;
use BlobSolutions\WooCommerceVcrAm\Vendor\Psr\Http\Message\RequestInterface;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);
    Functions\when('get_user_locale')->justReturn('en_US');
});

function directoryConfig(?string $apiKey = 'test-key'): Configuration
{
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn($apiKey);

    return $config;
}

/**
 * What VCR's search answered for "47" on 2026-11-23: ten goods codes that
 * merely start with 47, and the one activity code a merchant typing "47"
 * means.
 *
 * @param array{product?: list<ClassifierSearchItem>, service?: list<ClassifierSearchItem>} $answers
 */
function directoryWith(array $answers, ?Language $expectedLanguage = null): Directory
{
    $searcher = Mockery::mock(Searcher::class);
    $searcher->allows('search')->andReturnUsing(
        static function (string $query, OfferType $type, Language $language) use ($answers, $expectedLanguage): array {
            if ($expectedLanguage !== null) {
                expect($language)->toBe($expectedLanguage);
            }

            return $answers[$type->value] ?? [];
        },
    );

    $factory = Mockery::mock(SearcherFactory::class);
    $factory->allows('create')->with('test-key')->andReturn($searcher);

    return new Directory(directoryConfig(), $factory);
}

it('knows a code the search returns exactly, with its name', function (): void {
    $directory = directoryWith([
        'product' => [new ClassifierSearchItem('6109', 'T-shirts, singlets and other vests')],
    ]);

    $verdict = $directory->check('6109');

    expect($verdict->isKnown())->toBeTrue()
        ->and($verdict->entry?->label())->toBe('6109 — T-shirts, singlets and other vests');
});

it('refuses "47", the case that reached a live store, and offers 47.00 near the top', function (): void {
    $directory = directoryWith([
        'product' => [
            new ClassifierSearchItem('4701', 'Mechanical wood pulp'),
            new ClassifierSearchItem('4702', 'Chemical wood pulp, dissolving grades'),
            new ClassifierSearchItem('4703', 'Chemical wood pulp, soda or sulphate'),
        ],
        'service' => [
            new ClassifierSearchItem('47.00', 'Retail trade services'),
            new ClassifierSearchItem('46.47', 'Wholesale of household goods'),
        ],
    ]);

    $verdict = $directory->check('47');
    $codes = array_map(static fn ($entry): string => $entry->code, $verdict->suggestions);

    expect($verdict->isUnknown())->toBeTrue()
        ->and($codes)->toBe(['4701', '47.00', '4702', '46.47']);
});

it('says nothing about the code when VCR cannot be reached, and does not remember it', function (): void {
    $searcher = Mockery::mock(Searcher::class);
    $searcher->allows('search')->andThrow(
        new VcrNetworkException(Mockery::mock(RequestInterface::class), new RuntimeException('timed out')),
    );
    $factory = Mockery::mock(SearcherFactory::class);
    $factory->allows('create')->andReturn($searcher);

    Functions\expect('set_transient')->never();

    $verdict = (new Directory(directoryConfig(), $factory))->check('6109');

    expect($verdict->isKnown())->toBeFalse()
        ->and($verdict->isUnknown())->toBeFalse()
        ->and($verdict->failure?->problem)->toBe(ConnectionProblem::Unreachable);
});

it('does not call VCR without an API key', function (): void {
    $factory = Mockery::mock(SearcherFactory::class);
    $factory->expects('create')->never();

    $verdict = (new Directory(directoryConfig(null), $factory))->check('6109');

    expect($verdict->failure?->problem)->toBe(ConnectionProblem::NoApiKey);
});

it('answers a checked code from the cache, per language', function (): void {
    Functions\when('get_transient')->justReturn([
        'en:6109' => ['known' => true, 'code' => '6109', 'title' => 'T-shirts', 'suggestions' => []],
    ]);
    $factory = Mockery::mock(SearcherFactory::class);
    $factory->expects('create')->never();

    $verdict = (new Directory(directoryConfig(), $factory))->check('6109');

    expect($verdict->entry?->label())->toBe('6109 — T-shirts');
});

it('asks for names in the admin\'s language', function (): void {
    Functions\when('get_user_locale')->justReturn('ru_RU');

    $directory = directoryWith(
        ['product' => [new ClassifierSearchItem('6109', 'Майки')]],
        expectedLanguage: Language::Russian,
    );

    expect($directory->check('6109')->entry?->title)->toBe('Майки');
});

it('lists goods and activity matches without repeating a code', function (): void {
    $directory = directoryWith([
        'product' => [new ClassifierSearchItem('6101'), new ClassifierSearchItem('6102')],
        'service' => [new ClassifierSearchItem('61.10')],
    ]);

    $codes = array_map(static fn ($entry): string => $entry->code, $directory->search('61'));

    expect($codes)->toBe(['6101', '61.10', '6102']);
});
