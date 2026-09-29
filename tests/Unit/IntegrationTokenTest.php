<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit;

use BlobSolutions\WooCommerceVcrAm\IntegrationToken;

it('names the plugin and the platform it runs on', function (): void {
    $token = IntegrationToken::build('0.1.8', '7.1', '11.1', '8.3.14');

    expect($token)->toBe('vcr-am-woocommerce/0.1.8 (WordPress/7.1; WooCommerce/11.1; PHP/8.3.14)');
});

it('keeps the shapes real platforms report', function (): void {
    // WordPress nightlies and distro-patched PHP both carry suffixes; neither
    // is a reason to drop the part.
    $token = IntegrationToken::build('0.1.8', '7.1-RC1-58217', '11.1.0-beta.2', '8.3.14-1+ubuntu24.04');

    expect($token)->toBe(
        'vcr-am-woocommerce/0.1.8 (WordPress/7.1-RC1-58217; WooCommerce/11.1.0-beta.2; PHP/8.3.14-1+ubuntu24.04)',
    );
});

it('describes what it can when a platform will not say its version', function (): void {
    // WooCommerce not loaded yet, for instance: the token still identifies the
    // plugin, which is the part support needs.
    expect(IntegrationToken::build('0.1.8', '7.1', null, '8.3.14'))
        ->toBe('vcr-am-woocommerce/0.1.8 (WordPress/7.1; PHP/8.3.14)');

    expect(IntegrationToken::build('0.1.8', null, null, null))
        ->toBe('vcr-am-woocommerce/0.1.8');
});

it('drops a platform version that is not one', function (): void {
    // A version string on WordPress is whatever the last filter returned. A
    // newline in there would be header injection, and the SDK refuses the
    // whole token over it — so the bad part goes, not the token.
    $token = IntegrationToken::build('0.1.8', "7.1\r\nX-Api-Key: stolen", '11.1', '8.3.14');

    expect($token)->toBe('vcr-am-woocommerce/0.1.8 (WooCommerce/11.1; PHP/8.3.14)');
});

it('drops a version too long to be one', function (): void {
    $token = IntegrationToken::build('0.1.8', str_repeat('9', 64), '11.1', '8.3.14');

    expect($token)->toBe('vcr-am-woocommerce/0.1.8 (WooCommerce/11.1; PHP/8.3.14)');
});

it('sends nothing at all when its own version is unusable', function (): void {
    // Without our version the token adds nothing to what the SDK already says.
    expect(IntegrationToken::build('', '7.1', '11.1', '8.3.14'))->toBeNull();
    expect(IntegrationToken::build("0.1.8\nX-Api-Key: stolen", '7.1', '11.1', '8.3.14'))->toBeNull();
});

it('trims surrounding whitespace rather than rejecting over it', function (): void {
    // A stray newline around a version is noise from whatever assembled it,
    // not an injection attempt: what is left carries none of it.
    expect(IntegrationToken::build("  0.1.8\n", ' 7.1 ', '11.1', '8.3.14'))
        ->toBe('vcr-am-woocommerce/0.1.8 (WordPress/7.1; WooCommerce/11.1; PHP/8.3.14)');
});

it('stays well inside the length the SDK accepts', function (): void {
    $token = IntegrationToken::build(
        str_repeat('9', 32),
        str_repeat('9', 32),
        str_repeat('9', 32),
        str_repeat('9', 32),
    );

    expect($token)->not->toBeNull()
        ->and(strlen((string) $token))->toBeLessThan(200);
});
