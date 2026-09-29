<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use InvalidArgumentException;
use RuntimeException;

if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are diagnostic, surfaced via Logger or wp_die() (which escape themselves); per-arg esc_html on sprintf args is ritual noise.


/**
 * Encrypted-at-rest storage for sensitive configuration (currently the
 * VCR.AM API key).
 *
 * Uses libsodium's authenticated encryption (`crypto_secretbox`). The
 * encryption key is derived from `wp_salt('auth')` so:
 *
 *   - Each WordPress install has its own unique key with no extra
 *     ceremony (the salt already exists in `wp-config.php`).
 *   - A database dump without `wp-config.php` cannot be decrypted.
 *   - Rotating `auth` salts invalidates stored ciphertext, forcing the
 *     admin to re-enter the API key — the desirable behaviour after a
 *     salt rotation, not a bug.
 *
 * Storage format: base64( nonce(24) || ciphertext ).
 *
 * Decryption failures (corrupt data, tampered ciphertext, salt rotation)
 * return null rather than throwing — the caller surfaces a "please
 * re-enter your API key" admin notice. Exceptions are reserved for
 * environment misconfiguration that the user can't recover from at
 * runtime (no libsodium at all, native or polyfilled).
 *
 * A host without the native ext-sodium is supported: WordPress bundles
 * the pure-PHP sodium_compat polyfill and loads it exactly when the
 * extension is absent, and encryption works through it. The one thing
 * the polyfill cannot do is wipe memory — see {@see self::wipe()}.
 */
/**
 * Not declared `final` so unit tests for ConnectionTester can mock
 * `get()` without booting Brain Monkey + WP options for every test —
 * there's no production extension point.
 */
class KeyStore
{
    public function __construct(
        private readonly string $optionName,
    ) {
        // WordPress guarantees this: the native extension since PHP 7.2, or
        // its own bundled polyfill when the extension was left out of the
        // build. Reaching the throw means neither is present, which is a
        // broken WordPress install rather than an unusual host.
        if (! function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException(
                'libsodium is required for VCR encrypted credential storage, and neither '
                . 'ext-sodium nor WordPress\'s bundled sodium_compat polyfill is available.',
            );
        }
    }

    public function put(string $plaintext): void
    {
        // Empty plaintext would round-trip cleanly (encrypts to a valid
        // empty-payload secretbox), but produces an internally inconsistent
        // store — `isSet()` would return true while the value is empty.
        // Callers that mean "clear the key" should use `forget()`.
        if ($plaintext === '') {
            throw new InvalidArgumentException(
                'KeyStore::put() refuses empty plaintext — use forget() to clear the value.',
            );
        }

        $key = $this->deriveKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        $encoded = base64_encode($nonce . $ciphertext);

        // autoload=false: the API key is read only on first use after request
        // boot, not on every page load. Keeps `wp_options` autoload payload lean.
        $saved = update_option($this->optionName, $encoded, false);

        $this->wipe($key);

        // The nonce is fresh per write, so the stored ciphertext always
        // changes — `update_option`'s "value-didn't-change → false" path
        // doesn't apply here. A `false` return is therefore a real DB write
        // failure that the admin needs to know about (silent failure would
        // leave the admin thinking the key was saved, until the next API
        // call fails with an auth error).
        if ($saved === false) {
            throw new RuntimeException(
                "Failed to persist encrypted credential to wp_options['{$this->optionName}'].",
            );
        }
    }

    public function get(): ?string
    {
        $encoded = get_option($this->optionName, null);
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $raw = base64_decode($encoded, true);
        $minLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ($raw === false) {
            $this->logFailure('stored value is not valid base64');

            return null;
        }
        if (strlen($raw) < $minLength) {
            $this->logFailure('stored ciphertext is shorter than nonce + MAC');

            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = $this->deriveKey();
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        $this->wipe($key);

        if ($plaintext === false) {
            $this->logFailure(
                'sodium_crypto_secretbox_open returned false — most likely cause: wp_salt(\'auth\') has rotated since the value was written; re-enter the credential to refresh the ciphertext',
            );

            return null;
        }

        return $plaintext;
    }

    public function isSet(): bool
    {
        return $this->get() !== null;
    }

    public function forget(): void
    {
        delete_option($this->optionName);
    }

    /**
     * Overwrite a derived key in memory, on the hosts where that is
     * actually possible.
     *
     * `sodium_memzero()` is defined on every WordPress install, but on a
     * host without the native ext-sodium the definition is WordPress's
     * bundled sodium_compat polyfill, and that implementation throws
     * `SodiumException` by design — PHP cannot overwrite a string's buffer
     * in place, so the polyfill refuses rather than pretending. Calling it
     * unguarded turned "save your API key" into a WordPress critical error
     * on those hosts, and left the settings page fatal on every later
     * render, because reading the key wipes a derived key too.
     *
     * `function_exists()` is the wrong question — the polyfill answers it
     * yes. `extension_loaded('sodium')` asks the real one: can this wipe
     * happen at all? Where it can't, the derived key is left to ordinary
     * garbage collection. That is weaker, but it is what the rest of
     * WordPress does with its secrets on the same host, and the
     * alternative on offer is not a wipe — it is an exception.
     *
     * @param-out string|null $derivedKey Discarded by `sodium_memzero()`
     *   where the wipe happened, untouched where it did not. Either way
     *   the caller is done with the value by the time this returns.
     */
    private function wipe(string &$derivedKey): void
    {
        if (! extension_loaded('sodium')) {
            return;
        }

        sodium_memzero($derivedKey);
    }

    private function deriveKey(): string
    {
        // SHA-256 of the auth salt — gives us a deterministic 32-byte key
        // (`SODIUM_CRYPTO_SECRETBOX_KEYBYTES`) without depending on the
        // salt's raw length.
        return hash('sha256', wp_salt('auth'), true);
    }

    /**
     * Surface decryption failures into the WC operational log channel
     * (WooCommerce → Status → Logs, source `vcr`). Routed there rather
     * than the WP debug log because that's where shop admins look for
     * plugin diagnostics — the WP debug log is a developer tool, the
     * WC log is operations-facing.
     *
     * Never includes the ciphertext, the salt, or the derived key.
     */
    private function logFailure(string $reason): void
    {
        $logger = new \BlobSolutions\WooCommerceVcrAm\Logging\Logger();
        $logger->error(sprintf(
            'KeyStore failed to decrypt option "%s": %s',
            $this->optionName,
            $reason,
        ));
    }
}
