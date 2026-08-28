<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;
use Brain\Monkey\Functions;
use Mockery;

beforeEach(function (): void {
    Functions\when('wp_salt')->justReturn(str_repeat('x', 64));
});

function withOptionMap(array $map): void
{
    Functions\when('get_option')->alias(
        function (string $name, mixed $default = null) use ($map): mixed {
            return $map[$name] ?? $default;
        },
    );
}

it('apiKey delegates to KeyStore', function (): void {
    Functions\when('get_option')->justReturn(null);

    $keyStore = new KeyStore('vcr_x');
    $config = new Configuration($keyStore);

    expect($config->apiKey())->toBeNull();
    expect($config->hasCredentials())->toBeFalse();
});

it('baseUrl falls back to SDK default when option is empty or missing', function (): void {
    withOptionMap([]);

    $config = new Configuration(new KeyStore('vcr_x'));

    expect($config->baseUrl())->toBe(VcrClient::DEFAULT_BASE_URL);
});

it('baseUrl falls back to SDK default when option is whitespace', function (): void {
    withOptionMap([Configuration::OPT_BASE_URL => '   ']);

    $config = new Configuration(new KeyStore('vcr_x'));

    expect($config->baseUrl())->toBe(VcrClient::DEFAULT_BASE_URL);
});

it('baseUrl returns the trimmed override when set', function (): void {
    withOptionMap([Configuration::OPT_BASE_URL => '  https://staging.vcr.am/api/v1  ']);

    $config = new Configuration(new KeyStore('vcr_x'));

    expect($config->baseUrl())->toBe('https://staging.vcr.am/api/v1');
});

it('isTestMode is false unless option is exactly "yes"', function (): void {
    withOptionMap([Configuration::OPT_TEST_MODE => 'yes']);
    expect((new Configuration(new KeyStore('vcr_x')))->isTestMode())->toBeTrue();

    withOptionMap([Configuration::OPT_TEST_MODE => 'no']);
    expect((new Configuration(new KeyStore('vcr_x')))->isTestMode())->toBeFalse();

    withOptionMap([]);
    expect((new Configuration(new KeyStore('vcr_x')))->isTestMode())->toBeFalse();
});

it('defaultCashierId returns null on missing or zero, positive int otherwise', function (): void {
    withOptionMap([]);
    expect((new Configuration(new KeyStore('vcr_x')))->defaultCashierId())->toBeNull();

    withOptionMap([Configuration::OPT_DEFAULT_CASHIER_ID => '0']);
    expect((new Configuration(new KeyStore('vcr_x')))->defaultCashierId())->toBeNull();

    withOptionMap([Configuration::OPT_DEFAULT_CASHIER_ID => '42']);
    expect((new Configuration(new KeyStore('vcr_x')))->defaultCashierId())->toBe(42);
});

it('defaultDepartmentId returns null on missing or zero, positive int otherwise', function (): void {
    withOptionMap([]);
    expect((new Configuration(new KeyStore('vcr_x')))->defaultDepartmentId())->toBeNull();

    withOptionMap([Configuration::OPT_DEFAULT_DEPARTMENT_ID => '7']);
    expect((new Configuration(new KeyStore('vcr_x')))->defaultDepartmentId())->toBe(7);
});

/**
 * Configuration backed by a KeyStore that reports a usable API key, so the
 * credential half of isFullyConfigured() is satisfied and the assertions
 * below are about the remaining fields.
 */
function configWithCredentials(): Configuration
{
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('get')->andReturn('an-api-key');

    return new Configuration($keyStore);
}

it('isFullyConfigured requires an API key', function (): void {
    withOptionMap([
        Configuration::OPT_DEFAULT_CASHIER_ID => '1',
        Configuration::OPT_DEFAULT_DEPARTMENT_ID => '1',
    ]);

    expect((new Configuration(new KeyStore('vcr_x')))->isFullyConfigured())->toBeFalse();
});

it('isFullyConfigured requires a cashier', function (): void {
    withOptionMap([Configuration::OPT_DEFAULT_DEPARTMENT_ID => '1']);

    expect(configWithCredentials()->isFullyConfigured())->toBeFalse();
});

it('isFullyConfigured does NOT require a department', function (): void {
    // The department is an override, not a prerequisite. Requiring it is
    // what forced every admin to pick one, and a guessed department books
    // the store's receipts under a tax regime it may not owe. With none
    // set, each line inherits the department of the offer it references.
    withOptionMap([Configuration::OPT_DEFAULT_CASHIER_ID => '1']);

    $config = configWithCredentials();

    expect($config->defaultDepartmentId())->toBeNull()
        ->and($config->isFullyConfigured())->toBeTrue();
});

it('shippingSku returns null when option is unset', function (): void {
    withOptionMap([]);

    expect((new Configuration(new KeyStore('vcr_x')))->shippingSku())->toBeNull();
});

it('shippingSku returns null when option is whitespace', function (): void {
    withOptionMap([Configuration::OPT_SHIPPING_SKU => '   ']);

    expect((new Configuration(new KeyStore('vcr_x')))->shippingSku())->toBeNull();
});

it('shippingSku returns the trimmed value when set', function (): void {
    withOptionMap([Configuration::OPT_SHIPPING_SKU => '  ship-001  ']);

    expect((new Configuration(new KeyStore('vcr_x')))->shippingSku())->toBe('ship-001');
});

it('feeSku returns null when option is unset', function (): void {
    withOptionMap([]);

    expect((new Configuration(new KeyStore('vcr_x')))->feeSku())->toBeNull();
});

it('feeSku returns the trimmed value when set', function (): void {
    withOptionMap([Configuration::OPT_FEE_SKU => 'srv-fee']);

    expect((new Configuration(new KeyStore('vcr_x')))->feeSku())->toBe('srv-fee');
});

it('commentSource defaults to the order number when unset', function (): void {
    withOptionMap([]);

    expect((new Configuration(new KeyStore('vcr_x')))->commentSource())
        ->toBe(Configuration::COMMENT_SOURCE_ORDER_NUMBER);
});

it('commentSource returns a valid stored value verbatim', function (): void {
    withOptionMap([Configuration::OPT_COMMENT_SOURCE => Configuration::COMMENT_SOURCE_TRANSACTION_ID]);
    expect((new Configuration(new KeyStore('vcr_x')))->commentSource())
        ->toBe(Configuration::COMMENT_SOURCE_TRANSACTION_ID);

    withOptionMap([Configuration::OPT_COMMENT_SOURCE => Configuration::COMMENT_SOURCE_OFF]);
    expect((new Configuration(new KeyStore('vcr_x')))->commentSource())
        ->toBe(Configuration::COMMENT_SOURCE_OFF);
});

it('commentSource falls back to the default for a stray stored value', function (): void {
    withOptionMap([Configuration::OPT_COMMENT_SOURCE => 'legacy_unknown_value']);

    expect((new Configuration(new KeyStore('vcr_x')))->commentSource())
        ->toBe(Configuration::DEFAULT_COMMENT_SOURCE);
});
