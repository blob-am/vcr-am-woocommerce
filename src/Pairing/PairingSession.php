<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The half of a pairing that must never leave this server.
 *
 * Between the two halves of the handshake the merchant's browser goes to
 * vcr.am and comes back, so the PKCE verifier and the `state` nonce have to
 * survive one round trip without travelling with them. A transient is the
 * plugin's existing mechanism for short-lived server-side state, and this is
 * the one place it holds something secret rather than a cached response.
 *
 * Keyed per user, because two administrators can be pairing at the same time
 * and a shared key would let the second one's redirect consume the first
 * one's verifier.
 *
 * Single use: {@see take()} deletes as it reads. The code on the other end is
 * single-use too, so a session that survived its own exchange could only ever
 * serve a replay.
 *
 * Not declared `final` so unit tests can mock it; there is no production
 * extension point.
 */
class PairingSession
{
    /**
     * Long enough for a merchant to sign in to vcr.am, find the register and
     * read the consent screen; short enough that an abandoned attempt does not
     * leave a usable verifier lying in the options table for a day. The
     * server's own request expires in 30 minutes, so nothing is gained by
     * outliving that.
     */
    public const TTL_SECONDS = 15 * MINUTE_IN_SECONDS;

    private const TRANSIENT_PREFIX = 'vcr_pairing_';

    public function start(int $userId, string $state, string $codeVerifier): void
    {
        set_transient(
            self::keyFor($userId),
            ['state' => $state, 'codeVerifier' => $codeVerifier],
            self::TTL_SECONDS,
        );
    }

    /**
     * Reads the pending session and clears it in the same breath.
     *
     * @return ?array{state: string, codeVerifier: string}
     */
    public function take(int $userId): ?array
    {
        $key = self::keyFor($userId);
        $stored = get_transient($key);
        delete_transient($key);

        if (! is_array($stored)) {
            return null;
        }

        $state = $stored['state'] ?? null;
        $codeVerifier = $stored['codeVerifier'] ?? null;

        // Both or nothing. Half a session cannot complete a handshake, and
        // carrying on with one field would mean skipping a check.
        if (! is_string($state) || ! is_string($codeVerifier)) {
            return null;
        }

        if ($state === '' || $codeVerifier === '') {
            return null;
        }

        return ['state' => $state, 'codeVerifier' => $codeVerifier];
    }

    public function forget(int $userId): void
    {
        delete_transient(self::keyFor($userId));
    }

    private static function keyFor(int $userId): string
    {
        return self::TRANSIENT_PREFIX . $userId;
    }
}
