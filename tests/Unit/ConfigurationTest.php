<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\VcrClient;
use Brain\Monkey\Functions;

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

it('isFullyConfigured requires apiKey AND cashier AND department', function (): void {
    // No credentials.
    withOptionMap([
        Configuration::OPT_DEFAULT_CASHIER_ID => '1',
        Configuration::OPT_DEFAULT_DEPARTMENT_ID => '1',
    ]);
    expect((new Configuration(new KeyStore('vcr_x')))->isFullyConfigured())->toBeFalse();
});
