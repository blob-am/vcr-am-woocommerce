<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProblem;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\IdentityReader;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\IdentityReaderFactory;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\RegisterIdentity;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\Psr\Http\Message\RequestInterface;
use BlobSolutions\WooCommerceVcrAm\Vendor\Psr\Http\Message\ResponseInterface;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);
    Functions\when('delete_transient')->justReturn(true);
});

function probeIdentity(): RegisterIdentity
{
    return new RegisterIdentity(
        vcrId: 90,
        crn: '99123456',
        isSandbox: true,
        tradingName: 'Kravec Sandbox',
        entityName: 'Kravec LLC',
        tin: '01234567',
    );
}

function probeConfig(?string $apiKey): Configuration
{
    $config = Mockery::mock(Configuration::class);
    $config->allows('apiKey')->andReturn($apiKey);
    $config->allows('baseUrl')->andReturn('https://vcr.am/api/v1');

    return $config;
}

function probeFactory(?RegisterIdentity $identity, ?Throwable $throws = null): IdentityReaderFactory
{
    $reader = Mockery::mock(IdentityReader::class);
    if ($throws !== null) {
        $reader->allows('identify')->andThrow($throws);
    } else {
        $reader->allows('identify')->andReturn($identity ?? probeIdentity());
    }

    $factory = Mockery::mock(IdentityReaderFactory::class);
    $factory->allows('create')->andReturn($reader);

    return $factory;
}

it('answers "no API key" without touching the network', function (): void {
    $factory = Mockery::mock(IdentityReaderFactory::class);
    $factory->expects('create')->never();

    $state = (new ConnectionProbe(probeConfig(null), $factory))->state();

    expect($state->isConnected())->toBeFalse()
        ->and($state->failure()?->problem)->toBe(ConnectionProblem::NoApiKey);
});

it('does not cache "no API key", because a save is what fixes it', function (): void {
    // Cache it and the checklist keeps saying "paste your key" on the page
    // that just accepted one.
    $written = false;
    Functions\when('set_transient')->alias(function () use (&$written): bool {
        $written = true;

        return true;
    });

    (new ConnectionProbe(probeConfig(null), probeFactory(null)))->state();

    expect($written)->toBeFalse();
});

it('reports the register the key belongs to', function (): void {
    $state = (new ConnectionProbe(probeConfig('key'), probeFactory(probeIdentity())))->state();

    expect($state->isConnected())->toBeTrue()
        ->and($state->identity()?->vcrId)->toBe(90)
        ->and($state->identity()?->isSandbox)->toBeTrue();
});

it('caches primitives, never the vendor response object', function (): void {
    // A serialized `…\Vendor\…` object in a transient is a hostage to
    // Strauss: re-prefix the namespace in a later build and every cached
    // row comes back as __PHP_Incomplete_Class.
    $stored = null;
    Functions\when('set_transient')->alias(function (string $key, mixed $value) use (&$stored): bool {
        $stored = $value;

        return true;
    });

    (new ConnectionProbe(probeConfig('key'), probeFactory(probeIdentity())))->state();

    expect($stored)->toBeArray()
        ->and($stored['identity'])->toBe([
            'vcrId' => 90,
            'crn' => '99123456',
            'isSandbox' => true,
            'tradingName' => 'Kravec Sandbox',
            'entityName' => 'Kravec LLC',
            'tin' => '01234567',
        ]);
});

it('rebuilds a cached identity without asking the API again', function (): void {
    Functions\when('get_transient')->justReturn(['identity' => probeIdentity()->toArray()]);

    $factory = Mockery::mock(IdentityReaderFactory::class);
    $factory->expects('create')->never();

    $state = (new ConnectionProbe(probeConfig('key'), $factory))->state();

    expect($state->identity()?->tin)->toBe('01234567');
});

it('treats a cache row written by an older version as a miss', function (): void {
    // Transients outlive plugin upgrades. A row missing a field this
    // version reads has to re-probe, not return a half-built identity.
    Functions\when('get_transient')->justReturn(['identity' => ['vcrId' => 90]]);

    $state = (new ConnectionProbe(probeConfig('key'), probeFactory(probeIdentity())))->state();

    expect($state->identity()?->tradingName)->toBe('Kravec Sandbox');
});

it('classifies a failure and caches it briefly, request id included', function (): void {
    $stored = null;
    $ttl = null;
    Functions\when('set_transient')->alias(function (string $key, mixed $value, int $seconds) use (&$stored, &$ttl): bool {
        $stored = $value;
        $ttl = $seconds;

        return true;
    });

    $apiError = new VcrApiException(
        statusCode: 500,
        apiErrorMessage: 'Internal error',
        rawBody: '{}',
        request: Mockery::mock(RequestInterface::class),
        response: Mockery::mock(ResponseInterface::class),
        requestId: 'req_01HF',
    );

    $state = (new ConnectionProbe(probeConfig('key'), probeFactory(null, $apiError)))->state();

    expect($state->failure()?->problem)->toBe(ConnectionProblem::ServerError)
        ->and($state->failure()?->requestId)->toBe('req_01HF')
        ->and($stored['problem'])->toBe('ServerError')
        // A failure is cached for a minute, not an hour: long enough that
        // one page render costs one request, short enough that a merchant
        // who just fixed the cause is not told to wait.
        ->and($ttl)->toBe(MINUTE_IN_SECONDS);
});

it('reads a cached failure back as the same problem', function (): void {
    Functions\when('get_transient')->justReturn([
        'problem' => 'Unreachable',
        'requestId' => null,
        'detail' => 'cURL error 7',
    ]);

    $factory = Mockery::mock(IdentityReaderFactory::class);
    $factory->expects('create')->never();

    $state = (new ConnectionProbe(probeConfig('key'), $factory))->state();

    expect($state->failure()?->problem)->toBe(ConnectionProblem::Unreachable)
        ->and($state->failure()?->technicalDetail)->toBe('cURL error 7');
});

it('asks once per request even when three screens ask', function (): void {
    $reader = Mockery::mock(IdentityReader::class);
    $reader->expects('identify')->once()->andReturn(probeIdentity());

    $factory = Mockery::mock(IdentityReaderFactory::class);
    $factory->expects('create')->once()->andReturn($reader);

    $probe = new ConnectionProbe(probeConfig('key'), $factory);
    $probe->state();
    $probe->state();
    $probe->state();

    expect(true)->toBeTrue();
});

it('forgets the in-request answer on refresh', function (): void {
    $deleted = false;
    Functions\when('delete_transient')->alias(function (string $key) use (&$deleted): bool {
        if ($key === 'vcr_connection_state') {
            $deleted = true;
        }

        return true;
    });

    $reader = Mockery::mock(IdentityReader::class);
    $reader->expects('identify')->twice()->andReturn(probeIdentity());

    $factory = Mockery::mock(IdentityReaderFactory::class);
    $factory->allows('create')->andReturn($reader);

    $probe = new ConnectionProbe(probeConfig('key'), $factory);
    $probe->state();
    $probe->refresh();
    $probe->state();

    expect($deleted)->toBeTrue();
});
