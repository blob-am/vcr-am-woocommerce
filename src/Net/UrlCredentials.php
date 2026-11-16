<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Net;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Removes `user:pass@` from a URL before it is shown or sent anywhere.
 *
 * Most installs never have credentials in the base URL, but an admin who
 * pastes `https://api:secret@host/...` would otherwise hand the secret to
 * whoever they paste a status report to.
 */
final class UrlCredentials
{
    public static function strip(string $url): string
    {
        $parts = wp_parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        if (! isset($parts['user']) && ! isset($parts['pass'])) {
            return $url;
        }

        $rebuilt = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }

        if (isset($parts['path'])) {
            $rebuilt .= $parts['path'];
        }

        if (isset($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }

        return $rebuilt;
    }
}
