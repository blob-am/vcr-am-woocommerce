<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\ConnectionTester;
use BlobSolutions\WooCommerceVcrAm\Catalog\CashierLister;
use BlobSolutions\WooCommerceVcrAm\Catalog\CashierListerFactory;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

beforeEach(function (): void {
    Functions\when('wp_salt')->justReturn(str_repeat('x', 64));
    Functions\when('get_option')->justReturn(null);
    Functions\when('check_ajax_referer')->justReturn(1);
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('wp_unslash')->returnArg();
    Functions\when('esc_url_raw')->returnArg();

    // Default: every test gets stubs for both response helpers so a
    // mistaken success/failure routing always surfaces as a clear thrown
    // exception rather than "function not defined".
    Functions\when('wp_send_json_success')->alias(function (array $payload = []): void {
        throw new RuntimeException('json_success_sent: ' . json_encode($payload));
    });
    Functions\when('wp_send_json_error')->alias(function (array $payload = [], int $status = 0): void {
        throw new RuntimeException('json_error_sent: ' . ($payload['message'] ?? '?'));
    });

    $_POST = [];
});

afterEach(function (): void {
    $_POST = [];
});

it('registers the AJAX action and admin_enqueue_scripts hooks', function (): void {
    Actions\expectAdded('wp_ajax_vcr_test_connection')->once();
    Actions\expectAdded('admin_enqueue_scripts')->once();

    $keyStore = new KeyStore('vcr_test_keystore_option');
    (new ConnectionTester(
        $keyStore,
        Mockery::mock(CashierListerFactory::class),
        '/tmp/plugin.php',
        '0.1.0',
    ))->register();
});

/**
 * Build a ConnectionTester wired to a mocked CashierListerFactory and
 * KeyStore, ready for handle() invocation. The KeyStore mock returns
 * the saved API key (or null) per test.
 */
function makeTester(?string $savedApiKey, CashierListerFactory $factory): ConnectionTester
{
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('get')->andReturn($savedApiKey);

    return new ConnectionTester($keyStore, $factory, '/tmp/plugin.php', '0.1.0');
}

it('rejects requests without manage_woocommerce capability', function (): void {
    Functions\when('current_user_can')->justReturn(false);

    $captured = [];
    Functions\when('wp_send_json_error')->alias(function (array $payload, int $status = 0) use (&$captured): void {
        $captured = ['payload' => $payload, 'status' => $status];
        throw new RuntimeException('json_error_sent');
    });

    $tester = makeTester(null, Mockery::mock(CashierListerFactory::class));

    expect(fn () => $tester->handle())->toThrow(RuntimeException::class, 'json_error_sent');
    expect($captured['status'])->toBe(403)
        ->and($captured['payload']['message'])->toContain('do not have permission');
});

it('rejects when no API key is provided and none is stored', function (): void {
    $captured = null;
    Functions\when('wp_send_json_error')->alias(function (array $payload) use (&$captured): void {
        $captured = $payload;
        throw new RuntimeException('json_error_sent');
    });

    $tester = makeTester(null, Mockery::mock(CashierListerFactory::class));

    expect(fn () => $tester->handle())->toThrow(RuntimeException::class, 'json_error_sent');
    expect($captured['message'])->toContain('No API key provided');
});

it('falls back to the stored API key when the form field is empty', function (): void {
    $lister = Mockery::mock(CashierLister::class);
    $lister->expects('listCashiers')->andReturn([]);

    $createCall = null;
    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->expects('create')->andReturnUsing(function (string $apiKey, ?string $baseUrl) use (&$createCall, $lister): CashierLister {
        $createCall = ['apiKey' => $apiKey, 'baseUrl' => $baseUrl];

        return $lister;
    });

    Functions\when('wp_send_json_success')->alias(function (array $payload): void {
        throw new RuntimeException('json_success_sent');
    });
    Functions\when('wp_send_json_error')->alias(function (array $payload): void {
        throw new RuntimeException('json_error_sent: ' . ($payload['message'] ?? '?'));
    });

    $tester = makeTester('saved-key', $factory);

    expect(fn () => $tester->handle())->toThrow(RuntimeException::class, 'json_success_sent');
    expect($createCall)->toBe(['apiKey' => 'saved-key', 'baseUrl' => null]);
});

it('passes the form-typed base URL through to the factory when non-empty', function (): void {
    $_POST['api_key'] = 'form-key';
    $_POST['base_url'] = 'https://staging.vcr.am/api/v1';

    $lister = Mockery::mock(CashierLister::class);
    $lister->expects('listCashiers')->andReturn([]);

    $createCall = null;
    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->expects('create')->andReturnUsing(function (string $apiKey, ?string $baseUrl) use (&$createCall, $lister): CashierLister {
        $createCall = ['apiKey' => $apiKey, 'baseUrl' => $baseUrl];

        return $lister;
    });

    Functions\when('wp_send_json_success')->alias(function (): void {
        throw new RuntimeException('json_success_sent');
    });

    $tester = makeTester(null, $factory);

    expect(fn () => $tester->handle())->toThrow(RuntimeException::class, 'json_success_sent');
    expect($createCall)->toBe([
        'apiKey' => 'form-key',
        'baseUrl' => 'https://staging.vcr.am/api/v1',
    ]);
});

it('returns success with cashier count on successful listCashiers', function (): void {
    $_POST['api_key'] = 'k';

    // CashierListItem is final — build real instances. ConnectionTester
    // only counts them, doesn't read fields, so cheap stub data is fine.
    $cashier = new \BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\CashierListItem(
        deskId: 'X',
        internalId: 1,
        name: [],
    );

    $lister = Mockery::mock(CashierLister::class);
    $lister->expects('listCashiers')->andReturn([$cashier, $cashier, $cashier]);

    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $captured = null;
    Functions\when('wp_send_json_success')->alias(function (array $payload) use (&$captured): void {
        $captured = $payload;
        throw new RuntimeException('json_success_sent');
    });

    $tester = makeTester(null, $factory);

    expect(fn () => $tester->handle())->toThrow(RuntimeException::class, 'json_success_sent');
    expect($captured['count'])->toBe(3);
});

it('reports VcrException messages directly on failure', function (): void {
    $_POST['api_key'] = 'bad-key';

    $apiException = new VcrApiException(
        statusCode: 401,
        apiErrorCode: 'UNAUTHORIZED',
        apiErrorMessage: 'API key not valid',
        rawBody: '{}',
        request: Mockery::mock(RequestInterface::class),
        response: Mockery::mock(ResponseInterface::class),
    );

    $lister = Mockery::mock(CashierLister::class);
    $lister->expects('listCashiers')->andThrow($apiException);

    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $captured = null;
    Functions\when('wp_send_json_error')->alias(function (array $payload) use (&$captured): void {
        $captured = $payload;
        throw new RuntimeException('json_error_sent');
    });

    $tester = makeTester(null, $factory);

    expect(fn () => $tester->handle())->toThrow(RuntimeException::class, 'json_error_sent');
    expect($captured['message'])->toContain('HTTP 401')
        ->and($captured['message'])->toContain('API key not valid');
});

it('wraps non-VcrException throwables under a generic "Unexpected error" message', function (): void {
    $_POST['api_key'] = 'k';

    $lister = Mockery::mock(CashierLister::class);
    $lister->expects('listCashiers')->andThrow(new RuntimeException('socket exploded'));

    $factory = Mockery::mock(CashierListerFactory::class);
    $factory->allows('create')->andReturn($lister);

    $captured = null;
    Functions\when('wp_send_json_error')->alias(function (array $payload) use (&$captured): void {
        $captured = $payload;
        throw new RuntimeException('json_error_sent');
    });

    $tester = makeTester(null, $factory);

    expect(fn () => $tester->handle())->toThrow(RuntimeException::class, 'json_error_sent');
    expect($captured['message'])->toContain('Unexpected error')
        ->and($captured['message'])->toContain('socket exploded');
});
