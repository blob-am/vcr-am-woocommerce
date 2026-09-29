<?php

declare(strict_types=1);

/**
 * Lets a test pretend the host has no native ext-sodium, the way a good
 * share of shared hosting does.
 *
 * There is no way to unload an extension from a running PHP process, and
 * the difference matters: with the extension absent WordPress loads its
 * bundled pure-PHP sodium_compat polyfill, which defines every sodium
 * function — so `function_exists()` says yes — but whose `memzero()`
 * throws `SodiumException` on purpose, because PHP cannot overwrite a
 * string's buffer in place.
 *
 * KeyStore lives in the `...\Settings` namespace and calls both functions
 * unqualified, so PHP resolves them against that namespace first and only
 * then against the global one. Declaring them here therefore intercepts
 * exactly KeyStore's calls, and nothing else in the suite. Both delegate
 * to the real function unless a test has asked for the polyfill-only
 * host, so the rest of the suite is unaffected.
 */

namespace BlobSolutions\WooCommerceVcrAm\Tests {
    final class SodiumEnvironment
    {
        /** True while a test is pretending the native extension is absent. */
        public static bool $nativeExtensionHidden = false;

        /**
         * Run $scenario on a host that has only WordPress's polyfill.
         *
         * @template T
         * @param  callable(): T $scenario
         * @return T
         */
        public static function withoutNativeExtension(callable $scenario): mixed
        {
            self::$nativeExtensionHidden = true;

            try {
                return $scenario();
            } finally {
                self::$nativeExtensionHidden = false;
            }
        }
    }
}

namespace BlobSolutions\WooCommerceVcrAm\Settings {
    use BlobSolutions\WooCommerceVcrAm\Tests\SodiumEnvironment;

    function extension_loaded(string $extension): bool
    {
        if ($extension === 'sodium' && SodiumEnvironment::$nativeExtensionHidden) {
            return false;
        }

        return \extension_loaded($extension);
    }

    /**
     * Mirrors ParagonIE_Sodium_Compat::memzero(), which is what
     * `sodium_memzero` resolves to on a host without the extension. The
     * message is quoted from wp-includes/sodium_compat/src/Compat.php.
     */
    function sodium_memzero(string &$string): void
    {
        if (SodiumEnvironment::$nativeExtensionHidden) {
            throw new \SodiumException(
                'This is not implemented in sodium_compat, as it is not possible to securely '
                . 'wipe memory from PHP. To fix this error, make sure libsodium is installed '
                . 'and the PHP extension is enabled.',
            );
        }

        \sodium_memzero($string);
    }
}
