<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Settings;

use InvalidArgumentException;
use RuntimeException;

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
 * runtime (libsodium missing).
 */
final class KeyStore
{
    public function __construct(
        private readonly string $optionName,
    ) {
        if (! function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException(
                'libsodium (ext-sodium) is required for VCR encrypted credential storage. '
                . 'It ships with PHP 7.2+ and is enabled by default; check your PHP build.',
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

        sodium_memzero($key);

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
        sodium_memzero($key);

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

    private function deriveKey(): string
    {
        // SHA-256 of the auth salt — gives us a deterministic 32-byte key
        // (`SODIUM_CRYPTO_SECRETBOX_KEYBYTES`) without depending on the
        // salt's raw length.
        return hash('sha256', wp_salt('auth'), true);
    }

    /**
     * Surface decryption failures into the WP debug log. Gated by
     * `WP_DEBUG_LOG` because emitting on every page load of a broken
     * install would spam production logs; admins debugging "why does my
     * API key not work after the security update" have to enable
     * `WP_DEBUG` + `WP_DEBUG_LOG` to see the trail, but that's the
     * standard WP debugging entrypoint.
     *
     * Never includes the ciphertext, the salt, or the derived key.
     */
    private function logFailure(string $reason): void
    {
        if (! defined('WP_DEBUG_LOG') || ! WP_DEBUG_LOG) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf(
            '[VCR KeyStore] failed to decrypt option "%s": %s',
            $this->optionName,
            $reason,
        ));
    }
}
