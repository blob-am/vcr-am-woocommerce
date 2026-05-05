<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Net;

/**
 * Validate that a URL is safe to send the VCR API key to (and to POST
 * order data into). The API key is the most sensitive thing the plugin
 * holds; an admin (or a hostile shop manager with `manage_woocommerce`
 * cap) who repoints the base URL can otherwise exfiltrate the key by
 * making the plugin's outbound calls hit:
 *
 *   - `http://169.254.169.254/...` — cloud-metadata service (AWS / GCP /
 *     Azure all expose the instance's IAM role tokens here).
 *   - `http://127.0.0.1:6379/` — a Redis instance bound to loopback.
 *   - `http://10.x / 192.168.x / 172.16-31.x` — internal corporate
 *     services unreachable from the public internet.
 *   - `file:///etc/passwd` — non-HTTP scheme, no defence whatsoever.
 *
 * The validation rules are conservative:
 *
 *   - Scheme MUST be `http` or `https`. Anything else fails.
 *   - Host MUST resolve. We cannot DNS-resolve from arbitrary plugin
 *     contexts in tests, so name-based checks are limited to literal
 *     IPs in the URL string. WP core's `wp_http_validate_url()` does
 *     a parallel DNS check that we don't duplicate here — callers
 *     that go through `wp_remote_*` already get that for free.
 *   - Host MUST NOT be a literal IP in any RFC1918 / loopback /
 *     link-local / IPv6 ULA range.
 *   - Port MUST be either default (omitted), 80, 443, or in the
 *     "ephemeral / app" range >=1024. Sub-1024 non-standard ports
 *     are blocked because they hint at internal services (8080 is
 *     allowed, 22 is not).
 *
 * The validator is read-only — it never mutates input, just returns
 * `null` on a clean URL and a human-readable rejection reason
 * otherwise. Callers compose this into their save / send flow and
 * decide how to surface the error.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SafeUrlValidator
{
    /**
     * Returns `null` when `$url` is safe to send credentials to. Any
     * non-null return is the rejection reason; the caller should
     * surface it to the admin / log.
     */
    public function reject(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return 'URL is empty.';
        }

        $parts = wp_parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'URL is malformed (missing scheme or host).';
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return sprintf('URL scheme "%s" not allowed (only http / https).', $scheme);
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '') {
            return 'URL host is empty.';
        }

        // PHP's parse_url returns IPv6 hosts WITH the surrounding
        // brackets (`[::1]`). Strip them before any IP-validation
        // check, otherwise FILTER_VALIDATE_IP rejects the bracketed
        // form as not-an-IP and we mis-classify the host as a name.
        $hostBare = trim($host, '[]');

        // Literal IPv4 / IPv6: check against private / reserved ranges.
        if (filter_var($hostBare, FILTER_VALIDATE_IP) !== false) {
            if ($this->isUnsafeLiteralIp($hostBare)) {
                return sprintf('URL host "%s" resolves to a private / loopback / link-local address.', $host);
            }
        } else {
            // Hostname-shaped — block obvious local names. Real DNS
            // resolution is left to the WP HTTP layer which will fail
            // safely on unreachable hosts.
            if ($this->isUnsafeHostname($host)) {
                return sprintf('URL host "%s" looks like a local / internal name.', $host);
            }
        }

        if (isset($parts['port']) && is_int($parts['port'])) {
            $port = $parts['port'];
            if ($port < 1024 && $port !== 80 && $port !== 443) {
                return sprintf('URL port %d is in the privileged range and not 80/443.', $port);
            }
        }

        return null;
    }

    /**
     * RFC1918, loopback, link-local, IPv6 ULA, multicast, broadcast,
     * documentation, etc.
     *
     * Approach:
     *
     *   1. Try `FILTER_VALIDATE_IP` with `FILTER_FLAG_NO_PRIV_RANGE |
     *      FILTER_FLAG_NO_RES_RANGE`. This catches all RFC1918 IPv4 and
     *      most reserved ranges. Result `false` -> unsafe.
     *
     *   2. PHP's filter extension has historically been inconsistent
     *      about IPv6 reserved ranges (the `FILTER_FLAG_NO_RES_RANGE`
     *      flag is documented as IPv4-only on some versions). Explicit
     *      string-prefix checks for `::1` (loopback), `fe80::/10`
     *      (link-local), `fc00::/7` (ULA), and the `::ffff:` IPv4-
     *      mapped form provide deterministic coverage.
     */
    private function isUnsafeLiteralIp(string $host): bool
    {
        $strippedV6 = trim($host, '[]');

        $clean = filter_var(
            $strippedV6,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
        if ($clean === false) {
            return true;
        }

        // Belt-and-braces explicit IPv6 checks — see method doc-block.
        $lower = strtolower($strippedV6);
        if ($lower === '::1') {
            return true;
        }
        if (str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9')
            || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')
        ) {
            // fe80::/10 — link-local
            return true;
        }
        if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
            // fc00::/7 — unique local addresses
            return true;
        }
        if (str_starts_with($lower, '::ffff:')) {
            // IPv4-mapped IPv6 — recurse on the mapped IPv4 portion.
            $mapped = substr($lower, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP) !== false) {
                return $this->isUnsafeLiteralIp($mapped);
            }
        }

        return false;
    }

    private function isUnsafeHostname(string $host): bool
    {
        // Common local / internal-only hostnames. The list intentionally
        // covers the names humans type by hand; admins typing `localhost`
        // or `vcr.local` is a misconfiguration we want to catch.
        if ($host === 'localhost') {
            return true;
        }
        if (str_ends_with($host, '.local')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.lan')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.example')
        ) {
            return true;
        }

        return false;
    }
}
