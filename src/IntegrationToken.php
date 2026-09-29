<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The `User-Agent` product token this plugin adds to the SDK's own, so that a
 * request in vcr.am's log can be traced to a plugin version and a platform:
 *
 *     vcr-am-woocommerce/0.1.8 (WordPress/7.1; WooCommerce/11.1; PHP/8.3.14)
 *
 * Until the SDK grew somewhere to put this, every caller looked alike in that
 * log — raw SDK use, this plugin, and a plugin three versions out of date — so
 * a support question could not be answered from it.
 *
 * Every part is optional and every part is sanitised. A token is a diagnostic,
 * and no diagnostic is worth failing a fiscal receipt for: a platform that
 * cannot say its own version is described without it, and a version string
 * some other plugin's filter has mangled is dropped rather than passed to the
 * SDK, which would refuse the whole thing.
 */
final class IntegrationToken
{
    /** The product name, fixed. Matches the plugin slug. */
    public const PRODUCT = 'vcr-am-woocommerce';

    /**
     * Cap on each version fragment. The SDK caps the whole token at 200
     * characters; four fragments this size cannot approach that even before
     * the labels, so the SDK's refusal is unreachable from here.
     */
    private const MAX_VERSION_LENGTH = 32;

    /**
     * Reads the running site. Call once per request — the answer cannot change
     * within one.
     */
    public static function forPlugin(string $pluginVersion): ?string
    {
        return self::build(
            $pluginVersion,
            function_exists('get_bloginfo') ? get_bloginfo('version') : null,
            defined('WC_VERSION') ? (string) constant('WC_VERSION') : null,
            PHP_VERSION,
        );
    }

    /**
     * The composition itself, with every input supplied — which is how the
     * tests reach the cases a live site will not reproduce on demand.
     */
    public static function build(
        string $pluginVersion,
        ?string $wordPressVersion,
        ?string $wooCommerceVersion,
        ?string $phpVersion,
    ): ?string {
        $version = self::sanitizeVersion($pluginVersion);

        // Without our own version the token says nothing the SDK's own does
        // not already say, so there is nothing worth sending.
        if ($version === null) {
            return null;
        }

        $platform = [];

        foreach (
            [
                'WordPress' => $wordPressVersion,
                'WooCommerce' => $wooCommerceVersion,
                'PHP' => $phpVersion,
            ] as $label => $candidate
        ) {
            $sanitized = $candidate === null ? null : self::sanitizeVersion($candidate);

            if ($sanitized !== null) {
                $platform[] = $label . '/' . $sanitized;
            }
        }

        $token = self::PRODUCT . '/' . $version;

        return $platform === [] ? $token : $token . ' (' . implode('; ', $platform) . ')';
    }

    /**
     * Keeps what a version number is made of and drops the rest. WordPress
     * reports things like `7.1-RC1-58217` and PHP `8.3.14-1+ubuntu24.04`, both
     * of which survive intact; anything carrying a character that has no
     * business in a header does not.
     */
    private static function sanitizeVersion(string $version): ?string
    {
        $trimmed = trim($version);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_VERSION_LENGTH) {
            return null;
        }

        return preg_match('/\A[A-Za-z0-9.\-+_~]+\z/', $trimmed) === 1 ? $trimmed : null;
    }
}
