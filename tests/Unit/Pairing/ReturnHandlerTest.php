<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Pairing\PairingClientFactory;
use BlobSolutions\WooCommerceVcrAm\Pairing\PairingGateway;
use BlobSolutions\WooCommerceVcrAm\Pairing\PairingSession;
use BlobSolutions\WooCommerceVcrAm\Pairing\ReturnHandler;
use BlobSolutions\WooCommerceVcrAm\Pairing\SettingsUrl;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\PairedRegister;
use Brain\Monkey\Functions;

/**
 * The handler ends in `wp_safe_redirect(); exit;`, which a unit test cannot
 * follow. Redirecting throws instead, so a test can assert where it was
 * heading and that nothing ran afterwards.
 */
final class RedirectedAway extends RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct('redirected to ' . $url);
    }
}

beforeEach(function (): void {
    $_GET = [];

    Functions\when('admin_url')->alias(
        static fn (string $path = ''): string => 'https://shop.example/wp-admin/' . $path,
    );
    Functions\when('add_query_arg')->alias(
        static fn (string $key, string $value, string $url): string => $url . '&' . $key . '=' . $value,
    );
    Functions\when('sanitize_text_field')->returnArg(1);
    Functions\when('wp_unslash')->returnArg(1);
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('wp_safe_redirect')->alias(static function (string $url): never {
        throw new RedirectedAway($url);
    });
});

afterEach(function (): void {
    $_GET = [];
});

/**
 * @param ?array{state: string, codeVerifier: string} $pending
 */
function makeReturnHandler(
    ?array $pending,
    ?PairingGateway $client = null,
    ?KeyStore $keyStore = null,
): ReturnHandler {
    $config = Mockery::mock(Configuration::class);
    $config->allows('baseUrl')->andReturn('https://vcr.am/api/v1');

    $session = Mockery::mock(PairingSession::class);
    $session->allows('take')->andReturn($pending);
    $session->allows('forget');

    $factory = Mockery::mock(PairingClientFactory::class);
    $factory->allows('create')->andReturn($client ?? Mockery::mock(PairingGateway::class));

    $probe = Mockery::mock(ConnectionProbe::class);
    $probe->allows('refresh');

    $cashiers = Mockery::mock(CashierCatalog::class);
    $cashiers->allows('refresh');

    $departments = Mockery::mock(DepartmentCatalog::class);
    $departments->allows('refresh');

    return new ReturnHandler(
        $config,
        $keyStore ?? Mockery::mock(KeyStore::class),
        $session,
        $factory,
        $probe,
        $cashiers,
        $departments,
    );
}

function pairedRegister(string $apiKey = 'the-new-key'): PairedRegister
{
    return new PairedRegister($apiKey, '2028-01-01T00:00:00Z', 42, '1234567890123', 'My Shop');
}

it('ignores a settings page that is not a pairing return', function (): void {
    // Every load of the settings screen runs through this handler. Only the
    // ones carrying a code are ours.
    $_GET = ['page' => 'wc-settings', 'tab' => 'vcr'];

    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->shouldNotReceive('put');

    makeReturnHandler(pending: null, keyStore: $keyStore)->handle();

    expect(true)->toBeTrue();
});

it('ignores a code arriving on somebody else\'s admin page', function (): void {
    $_GET = ['page' => 'options-general', 'code' => 'a-code', 'state' => 'a-state'];

    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->shouldNotReceive('put');

    makeReturnHandler(pending: null, keyStore: $keyStore)->handle();

    expect(true)->toBeTrue();
});

it('stores the key the exchange returned', function (): void {
    $_GET = ['page' => 'wc-settings', 'tab' => 'vcr', 'code' => 'a-code', 'state' => 'the-state'];

    $client = Mockery::mock(PairingGateway::class);
    $client->expects('exchangeCode')->with('a-code', 'the-verifier')->andReturn(pairedRegister());

    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->expects('put')->with('the-new-key');

    $handler = makeReturnHandler(
        pending: ['state' => 'the-state', 'codeVerifier' => 'the-verifier'],
        client: $client,
        keyStore: $keyStore,
    );

    expect(fn () => $handler->handle())
        ->toThrow(RedirectedAway::class, SettingsUrl::NOTICE_CONNECTED);
});

it('refuses a state that does not match the pending one', function (): void {
    // This check stands in for a nonce: the request is issued by vcr.am's
    // redirect, so no form on this site could have carried one.
    $_GET = ['page' => 'wc-settings', 'tab' => 'vcr', 'code' => 'a-code', 'state' => 'forged'];

    $client = Mockery::mock(PairingGateway::class);
    $client->shouldNotReceive('exchangeCode');

    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->shouldNotReceive('put');

    $handler = makeReturnHandler(
        pending: ['state' => 'the-real-state', 'codeVerifier' => 'the-verifier'],
        client: $client,
        keyStore: $keyStore,
    );

    expect(fn () => $handler->handle())
        ->toThrow(RedirectedAway::class, SettingsUrl::NOTICE_FAILED);
});

it('reports an expired attempt when nothing is pending', function (): void {
    $_GET = ['page' => 'wc-settings', 'tab' => 'vcr', 'code' => 'a-code', 'state' => 'a-state'];

    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->shouldNotReceive('put');

    $handler = makeReturnHandler(pending: null, keyStore: $keyStore);

    expect(fn () => $handler->handle())
        ->toThrow(RedirectedAway::class, SettingsUrl::NOTICE_EXPIRED);
});

it('reports a decline without trying to exchange anything', function (): void {
    $_GET = ['page' => 'wc-settings', 'tab' => 'vcr', 'error' => 'access_denied'];

    $client = Mockery::mock(PairingGateway::class);
    $client->shouldNotReceive('exchangeCode');

    $handler = makeReturnHandler(
        pending: ['state' => 'the-state', 'codeVerifier' => 'the-verifier'],
        client: $client,
    );

    expect(fn () => $handler->handle())
        ->toThrow(RedirectedAway::class, SettingsUrl::NOTICE_DENIED);
});

it('does not store anything when the exchange fails', function (): void {
    $_GET = ['page' => 'wc-settings', 'tab' => 'vcr', 'code' => 'a-code', 'state' => 'the-state'];

    $client = Mockery::mock(PairingGateway::class);
    $client->expects('exchangeCode')->andThrow(new RuntimeException('nope'));

    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->shouldNotReceive('put');

    $handler = makeReturnHandler(
        pending: ['state' => 'the-state', 'codeVerifier' => 'the-verifier'],
        client: $client,
        keyStore: $keyStore,
    );

    expect(fn () => $handler->handle())
        ->toThrow(RedirectedAway::class, SettingsUrl::NOTICE_FAILED);
});

it('reports a failure rather than a success when the key cannot be stored', function (): void {
    // The key is minted and never shown again, so silently redirecting to
    // "connected" would leave a store that believes it is paired and is not.
    $_GET = ['page' => 'wc-settings', 'tab' => 'vcr', 'code' => 'a-code', 'state' => 'the-state'];

    $client = Mockery::mock(PairingGateway::class);
    $client->allows('exchangeCode')->andReturn(pairedRegister());

    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->expects('put')->andThrow(new RuntimeException('option write failed'));

    $handler = makeReturnHandler(
        pending: ['state' => 'the-state', 'codeVerifier' => 'the-verifier'],
        client: $client,
        keyStore: $keyStore,
    );

    expect(fn () => $handler->handle())
        ->toThrow(RedirectedAway::class, SettingsUrl::NOTICE_FAILED);
});

it('refuses a return from a user who may not manage WooCommerce', function (): void {
    Functions\when('current_user_can')->justReturn(false);

    $_GET = ['page' => 'wc-settings', 'tab' => 'vcr', 'code' => 'a-code', 'state' => 'the-state'];

    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->shouldNotReceive('put');

    makeReturnHandler(
        pending: ['state' => 'the-state', 'codeVerifier' => 'the-verifier'],
        keyStore: $keyStore,
    )->handle();

    expect(true)->toBeTrue();
});
