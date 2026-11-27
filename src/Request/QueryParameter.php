<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Request;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One `$_GET` parameter, unslashed and sanitized, read without a nonce.
 *
 * Nearly every caller reads a marker that picks a notice, a tab or a list
 * filter, and checks the value against a closed set before using it. A nonce
 * guards a request that changes something, and those change nothing. The one
 * caller that acts is the pairing return (Pairing\ReturnHandler): it arrives
 * from VCR.AM's consent screen, which cannot carry a WordPress nonce, so its
 * `state` comparison and capability check stand in for one.
 *
 * WordPress's sniffs flag every such read with
 * NonceVerification.Recommended. Keeping the reads here leaves one exemption,
 * with its reason, instead of one per screen.
 */
final class QueryParameter
{
    /** The value, or null when the parameter is absent or not a string. */
    public static function text(string $key): ?string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only reads, see the class docblock.
        if (! isset($_GET[$key]) || ! is_string($_GET[$key])) {
            return null;
        }

        return sanitize_text_field(wp_unslash($_GET[$key]));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }
}
