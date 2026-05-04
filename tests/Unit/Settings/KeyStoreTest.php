<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use Brain\Monkey\Functions;

const TEST_OPTION_NAME = 'vcr_test_option';
const TEST_SALT = 'salt-from-wp-config-which-is-at-least-64-chars-long-aaaaaaaaaaaaaa';

/**
 * Pair of in-memory option stubs that emulate `update_option` /
 * `get_option` / `delete_option` against a single key. Reset on every
 * test via the shared `&$storage` reference.
 */
function stubOptionStorage(string &$storage, string $optionName): void
{
    Functions\when('update_option')->alias(
        function (string $name, mixed $value, bool $autoload = true) use (&$storage, $optionName): bool {
            unset($autoload);
            if ($name === $optionName) {
                $storage = is_string($value) ? $value : '';
            }

            return true;
        },
    );

    Functions\when('get_option')->alias(
        function (string $name, mixed $default = null) use (&$storage, $optionName): mixed {
            if ($name === $optionName) {
                return $storage === '' ? $default : $storage;
            }

            return $default;
        },
    );

    Functions\when('delete_option')->alias(
        function (string $name) use (&$storage, $optionName): bool {
            if ($name === $optionName) {
                $storage = '';
            }

            return true;
        },
    );
}

beforeEach(function (): void {
    Functions\when('wp_salt')->justReturn(TEST_SALT);
});

it('round-trips a plaintext value through put → get', function (): void {
    $storage = '';
    stubOptionStorage($storage, TEST_OPTION_NAME);

    $store = new KeyStore(TEST_OPTION_NAME);
    $store->put('vcr_live_my-secret-api-key-12345');

    expect($store->get())->toBe('vcr_live_my-secret-api-key-12345');
});

it('returns null when the option is unset', function (): void {
    $storage = '';
    stubOptionStorage($storage, TEST_OPTION_NAME);

    $store = new KeyStore(TEST_OPTION_NAME);

    expect($store->get())->toBeNull();
    expect($store->isSet())->toBeFalse();
});

it('isSet returns true after a put', function (): void {
    $storage = '';
    stubOptionStorage($storage, TEST_OPTION_NAME);

    $store = new KeyStore(TEST_OPTION_NAME);
    $store->put('any-key');

    expect($store->isSet())->toBeTrue();
});

it('forget removes the stored ciphertext', function (): void {
    $storage = '';
    stubOptionStorage($storage, TEST_OPTION_NAME);

    $store = new KeyStore(TEST_OPTION_NAME);
    $store->put('any-key');
    $store->forget();

    expect($store->get())->toBeNull();
    expect($store->isSet())->toBeFalse();
});

it('returns null when the stored ciphertext is malformed', function (): void {
    $storage = 'not-base64-not-cipher!';
    stubOptionStorage($storage, TEST_OPTION_NAME);

    $store = new KeyStore(TEST_OPTION_NAME);

    expect($store->get())->toBeNull();
});

it('returns null when ciphertext is too short to contain nonce + MAC', function (): void {
    $storage = base64_encode('too-short');
    stubOptionStorage($storage, TEST_OPTION_NAME);

    $store = new KeyStore(TEST_OPTION_NAME);

    expect($store->get())->toBeNull();
});

it('returns null when wp_salt has rotated (ciphertext no longer decryptable)', function (): void {
    $storage = '';
    stubOptionStorage($storage, TEST_OPTION_NAME);

    // Encrypt with the original salt.
    Functions\when('wp_salt')->justReturn(TEST_SALT);
    $store = new KeyStore(TEST_OPTION_NAME);
    $store->put('my-secret');

    // Caller rotates the salt; a fresh KeyStore reads the same option but
    // can't decrypt — and returns null rather than throwing.
    Functions\when('wp_salt')->justReturn('a-different-rotated-salt-' . str_repeat('x', 40));
    $store2 = new KeyStore(TEST_OPTION_NAME);

    expect($store2->get())->toBeNull();
});

it('produces different ciphertext for the same plaintext on repeat puts (random nonce)', function (): void {
    $storage = '';
    stubOptionStorage($storage, TEST_OPTION_NAME);

    $store = new KeyStore(TEST_OPTION_NAME);

    $store->put('same-plaintext');
    $first = $storage;

    $store->put('same-plaintext');
    $second = $storage;

    expect($first)->not->toBe($second);
    // But both decrypt to the same value.
    expect($store->get())->toBe('same-plaintext');
});
