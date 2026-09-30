<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Pairing\FailureDetail;
use BlobSolutions\WooCommerceVcrAm\Pairing\PairingClientFactory;
use BlobSolutions\WooCommerceVcrAm\Pairing\PairingGateway;
use BlobSolutions\WooCommerceVcrAm\Pairing\PairingSession;
use BlobSolutions\WooCommerceVcrAm\Pairing\SettingsUrl;
use BlobSolutions\WooCommerceVcrAm\Pairing\StartHandler;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\RegisterPairingRequestInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\PairingRequest;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Pairing\CodeVerifier;
use BlobSolutions\WooCommerceVcrAm\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use BlobSolutions\WooCommerceVcrAm\Vendor\Nyholm\Psr7\Response;
use Brain\Monkey\Functions;

/** Where the handler tried to send the browser, since a test cannot follow it. */
final class SentTo extends RuntimeException
{
    /** @param list<string> $allowedHosts */
    public function __construct(public readonly string $url, public readonly array $allowedHosts)
    {
        parent::__construct('sent to ' . $url);
    }
}

/**
 * Callbacks the handler added to `allowed_redirect_hosts`, captured from
 * add_filter and invoked to see which hosts they would let through. Asserting
 * on the resulting list rather than on "a filter was added" is what makes the
 * open-redirect test mean something.
 *
 * @var list<callable> $allowedRedirectHostFilters
 */
$allowedRedirectHostFilters = [];

function allowedRedirectHosts(): array
{
    global $allowedRedirectHostFilters;

    $hosts = [];

    foreach ($allowedRedirectHostFilters as $filter) {
        $hosts = $filter($hosts);
    }

    return $hosts;
}

beforeEach(function (): void {
    global $allowedRedirectHostFilters;
    $allowedRedirectHostFilters = [];

    Functions\when('add_filter')->alias(function (string $hook, callable $callback): bool {
        global $allowedRedirectHostFilters;

        if ($hook === 'allowed_redirect_hosts') {
            $allowedRedirectHostFilters[] = $callback;
        }

        return true;
    });
    Functions\when('remove_filter')->justReturn(true);

    Functions\when('admin_url')->alias(
        static fn (string $path = ''): string => 'https://shop.example/wp-admin/' . $path,
    );
    Functions\when('add_query_arg')->alias(
        static fn (string $key, string $value, string $url): string => $url . '&' . $key . '=' . $value,
    );
    Functions\when('home_url')->justReturn('https://shop.example');
    Functions\when('get_bloginfo')->justReturn('Example Shop');
    Functions\when('wp_specialchars_decode')->returnArg(1);
    Functions\when('check_admin_referer')->justReturn(true);
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('wp_parse_url')->alias(
        static fn (string $url, int $component): mixed => parse_url($url, $component),
    );
    // Every redirect goes through wp_safe_redirect, so what distinguishes a
    // redirect off-site is which host the handler allowed through the filter
    // to make it possible. Capturing that is the only way to see the guard.
    Functions\when('wp_safe_redirect')->alias(function (string $url): never {
        throw new SentTo($url, allowedHosts: allowedRedirectHosts());
    });
});

function makeStartHandler(
    PairingGateway $client,
    ?PairingSession $session = null,
    string $baseUrl = 'https://vcr.am/api/v1',
    ?FailureDetail $failureDetail = null,
): StartHandler {
    $config = Mockery::mock(Configuration::class);
    $config->allows('baseUrl')->andReturn($baseUrl);

    $factory = Mockery::mock(PairingClientFactory::class);
    $factory->allows('create')->andReturn($client);

    if ($session === null) {
        $session = Mockery::mock(PairingSession::class);
        $session->allows('start');
        $session->allows('forget');
    }

    return new StartHandler($config, $session, $factory, $failureDetail ?? new FailureDetail());
}

function registeredAt(string $connectUrl): PairingRequest
{
    return new PairingRequest('req_1', $connectUrl, '2030-01-01T00:00:00Z');
}

it('sends the merchant to the approval screen it was given', function (): void {
    $client = Mockery::mock(PairingGateway::class);
    $client->allows('registerRequest')->andReturn(registeredAt('https://vcr.am/connect?request=req_1'));

    try {
        makeStartHandler($client)->handle();
        expect(false)->toBeTrue('expected a redirect');
    } catch (SentTo $sent) {
        // Allowed for exactly this redirect, and only after the host was
        // checked against the one the plugin is configured to talk to.
        expect($sent->url)->toBe('https://vcr.am/connect?request=req_1')
            ->and($sent->allowedHosts)->toContain('vcr.am');
    }
});

it('registers the settings screen as the redirect, not admin-post', function (): void {
    // It is shown to the merchant on the consent screen, so it has to be a
    // page they would recognise as their own shop.
    $captured = null;

    $client = Mockery::mock(PairingGateway::class);
    $client->allows('registerRequest')->andReturnUsing(
        function (RegisterPairingRequestInput $input) use (&$captured): PairingRequest {
            $captured = $input;

            return registeredAt('https://vcr.am/connect?request=req_1');
        },
    );

    try {
        makeStartHandler($client)->handle();
    } catch (SentTo) {
        // expected
    }

    expect($captured?->redirectUri)->toBe('https://shop.example/wp-admin/admin.php?page=wc-settings&tab=vcr');
});

it('registers a challenge, never the verifier', function (): void {
    // Registering the challenge and keeping the verifier is the whole
    // mechanism; sending both would make PKCE decorative.
    $started = [];

    $session = Mockery::mock(PairingSession::class);
    $session->allows('start')->andReturnUsing(
        function (int $userId, string $state, string $verifier) use (&$started): void {
            $started = ['state' => $state, 'verifier' => $verifier];
        },
    );
    $session->allows('forget');

    $captured = null;
    $client = Mockery::mock(PairingGateway::class);
    $client->allows('registerRequest')->andReturnUsing(
        function (RegisterPairingRequestInput $input) use (&$captured): PairingRequest {
            $captured = $input;

            return registeredAt('https://vcr.am/connect?request=req_1');
        },
    );

    try {
        makeStartHandler($client, $session)->handle();
    } catch (SentTo) {
        // expected
    }

    expect($captured?->codeChallenge)->toBe(CodeVerifier::challengeFor($started['verifier']))
        ->and($captured?->codeChallenge)->not->toBe($started['verifier'])
        ->and($captured?->state)->toBe($started['state']);
});

it('names the store so the merchant can recognise it', function (): void {
    $captured = null;

    $client = Mockery::mock(PairingGateway::class);
    $client->allows('registerRequest')->andReturnUsing(
        function (RegisterPairingRequestInput $input) use (&$captured): PairingRequest {
            $captured = $input;

            return registeredAt('https://vcr.am/connect?request=req_1');
        },
    );

    try {
        makeStartHandler($client)->handle();
    } catch (SentTo) {
        // expected
    }

    expect($captured?->storeName)->toBe('Example Shop');
});

it('falls back to the host when the site has no title', function (): void {
    Functions\when('get_bloginfo')->justReturn('   ');

    $captured = null;
    $client = Mockery::mock(PairingGateway::class);
    $client->allows('registerRequest')->andReturnUsing(
        function (RegisterPairingRequestInput $input) use (&$captured): PairingRequest {
            $captured = $input;

            return registeredAt('https://vcr.am/connect?request=req_1');
        },
    );

    try {
        makeStartHandler($client)->handle();
    } catch (SentTo) {
        // expected
    }

    // An empty storeName is refused by the input DTO, so something has to
    // stand in — and a blank consent screen helps nobody.
    expect($captured?->storeName)->toBe('shop.example');
});

it('refuses to bounce the merchant to a host it was not configured to talk to', function (): void {
    // The connect URL comes back from the API. A misconfigured base URL or a
    // compromised response must not turn the connect button into an open
    // redirect for a logged-in administrator.
    $client = Mockery::mock(PairingGateway::class);
    $client->allows('registerRequest')->andReturn(registeredAt('https://evil.example/connect?request=req_1'));

    try {
        makeStartHandler($client)->handle();
        expect(false)->toBeTrue('expected a redirect');
    } catch (SentTo $sent) {
        expect($sent->url)->toContain(SettingsUrl::NOTICE_FAILED)
            ->and($sent->allowedHosts)->not->toContain('evil.example');
    }
});

it('clears the pending session when the request could not be registered', function (): void {
    // Otherwise a stale verifier sits there until it expires, and the next
    // attempt's return finds the wrong one.
    $session = Mockery::mock(PairingSession::class);
    $session->allows('start');
    $session->expects('forget')->with(7);

    $client = Mockery::mock(PairingGateway::class);
    $client->allows('registerRequest')->andThrow(new RuntimeException('network down'));

    expect(fn () => makeStartHandler($client, $session)->handle())
        ->toThrow(SentTo::class);
});

it('stores the session before the call, not after', function (): void {
    // If the request succeeds and the store then fails to remember the
    // verifier, the merchant approves a pairing nobody can exchange.
    $order = [];

    $session = Mockery::mock(PairingSession::class);
    $session->allows('start')->andReturnUsing(function () use (&$order): void {
        $order[] = 'session';
    });
    $session->allows('forget');

    $client = Mockery::mock(PairingGateway::class);
    $client->allows('registerRequest')->andReturnUsing(function () use (&$order): PairingRequest {
        $order[] = 'request';

        return registeredAt('https://vcr.am/connect?request=req_1');
    });

    try {
        makeStartHandler($client, $session)->handle();
    } catch (SentTo) {
        // expected
    }

    expect($order)->toBe(['session', 'request']);
});

it('refuses a user who may not manage WooCommerce', function (): void {
    Functions\when('current_user_can')->justReturn(false);
    Functions\when('wp_die')->alias(static function (): never {
        throw new RuntimeException('wp_die');
    });

    $client = Mockery::mock(PairingGateway::class);
    $client->shouldNotReceive('registerRequest');

    expect(fn () => makeStartHandler($client)->handle())->toThrow(RuntimeException::class, 'wp_die');
});

it('keeps the reason VCR.AM refused, so the notice can say it', function (): void {
    // The likeliest refusal by far: the API will not accept an http
    // `redirectUri`, so a shop whose wp-admin is not on https can never pair.
    // That sentence has to reach the merchant, not just the error log.
    $refusal = new VcrApiException(
        statusCode: 400,
        apiErrorMessage: 'redirectUri rejected (insecure_scheme): it must be an absolute https URL.',
        rawBody: '{}',
        request: (new Psr17Factory())->createRequest('POST', 'https://vcr.am/api/v1/connect/requests'),
        response: new Response(400),
    );

    $client = Mockery::mock(PairingGateway::class);
    $client->allows('registerRequest')->andThrow($refusal);

    $failureDetail = Mockery::mock(FailureDetail::class);
    $failureDetail->expects('remember')->with(7, $refusal->apiErrorMessage);

    expect(fn () => makeStartHandler($client, null, 'https://vcr.am/api/v1', $failureDetail)->handle())
        ->toThrow(SentTo::class);
});

it('keeps no reason when the failure was not the API refusing', function (): void {
    // A socket failure has nothing a merchant can act on, and "Could not
    // connect to VCR.AM" already says it.
    $client = Mockery::mock(PairingGateway::class);
    $client->allows('registerRequest')->andThrow(new RuntimeException('network down'));

    $failureDetail = Mockery::mock(FailureDetail::class);
    $failureDetail->expects('remember')->never();

    expect(fn () => makeStartHandler($client, null, 'https://vcr.am/api/v1', $failureDetail)->handle())
        ->toThrow(SentTo::class);
});
