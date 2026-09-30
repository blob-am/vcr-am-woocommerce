<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Pairing\StartHandler;
use BlobSolutions\WooCommerceVcrAm\Settings\ConnectButton;
use BlobSolutions\WooCommerceVcrAm\Settings\KeyStore;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('admin_url')->alias(
        static fn (string $path = ''): string => 'https://shop.example/wp-admin/' . $path,
    );
    Functions\when('wp_nonce_url')->alias(
        static fn (string $url, string $action): string => $url . '&_wpnonce=nonce-for-' . $action,
    );
    Functions\when('esc_url')->returnArg(1);
    Functions\when('esc_html')->returnArg(1);
});

/**
 * `get()` is stubbed rather than `isSet()`, and not by choice: Mockery does
 * not intercept `isSet` on this class — the real method runs and calls
 * `get()`. Stubbing the reader is what the rest of the suite does, and it
 * exercises the real `isSet()` on the way past.
 */
function connectButton(bool $keyIsSet): ConnectButton
{
    $keyStore = Mockery::mock(KeyStore::class);
    $keyStore->allows('get')->andReturn($keyIsSet ? 'an-api-key' : null);

    return new ConnectButton($keyStore);
}

it('points at the start action and carries a nonce', function (): void {
    // Without the nonce the button is a CSRF hole: a link anywhere else could
    // start a pairing in an administrator's session.
    $html = connectButton(keyIsSet: false)->render();

    expect($html)->toContain('admin-post.php?action=' . StartHandler::ACTION)
        ->and($html)->toContain('_wpnonce=nonce-for-' . StartHandler::NONCE_ACTION);
});

it('offers to connect when there is no key yet', function (): void {
    $html = connectButton(keyIsSet: false)->render();

    expect($html)->toContain('Connect to VCR.AM')
        ->and($html)->not->toContain('Reconnect');
});

it('offers to reconnect once a key is stored, and says it replaces it', function (): void {
    // A merchant with a working key needs to know the button is destructive
    // before pressing it, not after.
    $html = connectButton(keyIsSet: true)->render();

    expect($html)->toContain('Reconnect to VCR.AM')
        ->and($html)->toContain('replaces the stored key');
});

it('tells a merchant with no key that pasting one is still an option', function (): void {
    $html = connectButton(keyIsSet: false)->render();

    expect($html)->toContain('paste a key');
});

it('renders a button, not a bare link', function (): void {
    expect(connectButton(keyIsSet: false)->render())->toContain('class="button button-primary"');
});
