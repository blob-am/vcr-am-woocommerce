<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Pairing\PairingSession;
use Brain\Monkey\Functions;

/**
 * Stands in for the transient store, so a test can see what was written and
 * whether it was cleared.
 *
 * @var array<string, mixed> $store
 */
beforeEach(function (): void {
    $this->store = [];

    Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
        $this->store[$key] = $value;

        return true;
    });
    Functions\when('get_transient')->alias(fn (string $key): mixed => $this->store[$key] ?? false);
    Functions\when('delete_transient')->alias(function (string $key): bool {
        unset($this->store[$key]);

        return true;
    });
});

it('hands back what was started', function (): void {
    $session = new PairingSession();
    $session->start(7, 'the-state', 'the-verifier');

    expect($session->take(7))->toBe(['state' => 'the-state', 'codeVerifier' => 'the-verifier']);
});

it('clears the session as it reads it', function (): void {
    // Single use. The code on the other end is single-use too, so a session
    // that outlived its own exchange could only serve a replay.
    $session = new PairingSession();
    $session->start(7, 'the-state', 'the-verifier');

    $session->take(7);

    expect($session->take(7))->toBeNull();
});

it('keeps one user out of another one\'s session', function (): void {
    // Two administrators can be pairing at once; a shared key would let the
    // second one's redirect consume the first one's verifier.
    $session = new PairingSession();
    $session->start(7, 'seven-state', 'seven-verifier');
    $session->start(9, 'nine-state', 'nine-verifier');

    expect($session->take(9))->toBe(['state' => 'nine-state', 'codeVerifier' => 'nine-verifier'])
        ->and($session->take(7))->toBe(['state' => 'seven-state', 'codeVerifier' => 'seven-verifier']);
});

it('returns null when nothing is pending', function (): void {
    expect((new PairingSession())->take(7))->toBeNull();
});

it('refuses a half-written session', function (): void {
    // Half a session cannot complete a handshake, and carrying on with one
    // field would mean skipping a check.
    $this->store['vcr_pairing_7'] = ['state' => 'only-this'];

    expect((new PairingSession())->take(7))->toBeNull();
});

it('refuses a session whose fields are empty strings', function (): void {
    $this->store['vcr_pairing_7'] = ['state' => '', 'codeVerifier' => 'a-verifier'];

    expect((new PairingSession())->take(7))->toBeNull();
});

it('refuses a stored value that is not an array', function (): void {
    $this->store['vcr_pairing_7'] = 'not-an-array';

    expect((new PairingSession())->take(7))->toBeNull();
});

it('forgets a session without reading it', function (): void {
    $session = new PairingSession();
    $session->start(7, 'the-state', 'the-verifier');

    $session->forget(7);

    expect($session->take(7))->toBeNull();
});

it('does not outlive the server-side request it belongs to', function (): void {
    // The pairing request expires after 30 minutes on vcr.am; a verifier
    // sitting here for longer than that protects nothing and is just a
    // secret left in the options table.
    expect(PairingSession::TTL_SECONDS)->toBeLessThan(30 * 60);
});
