<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Receipt;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use WC_Order;

/**
 * Builds the public-facing VCR receipt URL for a fiscalised WooCommerce
 * order: `{host}/{locale}/r/{crn}/{urlId}` — the same shape served by the
 * VCR Next.js app at `app/[locale]/r/[crnId]/[srcIdOrUrlId]/page.tsx`.
 *
 * Why this lives in its own class:
 *
 *   - Display layer (email + thank-you + my-account) needs a stable
 *     "where do I send the customer to see their receipt?" answer
 *     without re-deriving from raw meta keys at every call site.
 *   - Self-hosted / sandbox VCR deployments (Vercel previews, on-prem
 *     trials) point at non-vcr.am API base URLs. We derive the receipt
 *     host from the configured API base so a single setting covers both.
 *   - Returns null cleanly when the order isn't yet successfully
 *     fiscalised, so display code is a single early-return check.
 *
 * Two filter hooks let stores override behaviour without forking:
 *
 *   - `vcr_receipt_host`  ($host, $apiBaseUrl) — change just the host
 *   - `vcr_receipt_url`   ($url, $order)        — replace the final URL
 */
class ReceiptUrlBuilder
{
    public function __construct(
        private readonly Configuration $config,
        private readonly FiscalStatusMeta $meta,
    ) {
    }

    /**
     * Returns the customer-visible receipt URL, or null if the order
     * isn't fiscalised yet (no Success status) or is missing one of the
     * identifiers required to address it (`crn`, `urlId`).
     *
     * Returning null here is the gate display code uses — no need for
     * callers to also re-check `meta->status()`.
     */
    public function build(WC_Order $order): ?string
    {
        if ($this->meta->status($order) !== FiscalStatus::Success) {
            return null;
        }

        $crn = $this->meta->crn($order);
        $urlId = $this->meta->urlId($order);

        if ($crn === null || $urlId === null) {
            return null;
        }

        $url = $this->composeUrl($crn, $urlId, $order);

        /**
         * Allow stores to swap the entire URL — e.g. a multisite running
         * a custom receipt viewer, or QA wanting to point at a staging
         * preview without touching the API base URL.
         */
        $filtered = apply_filters('vcr_receipt_url', $url, $order);

        return is_string($filtered) && $filtered !== '' ? $filtered : $url;
    }

    /**
     * Pure URL-assembly: `{host}/{locale}/r/{crn}/{urlId}`. Exposed so
     * sibling builders for other entity kinds (refunds, prepayments)
     * can share the same host and locale-derivation logic without
     * duplicating it. Does NOT fire the `vcr_receipt_url` filter — that
     * filter is sale-specific; sibling builders fire their own.
     *
     * `$localeContext` is passed through to {@see self::locale()} so
     * the `vcr_receipt_locale` filter receives the entity that has the
     * customer-language signal (the parent order, for refunds).
     */
    public function composeUrl(string $crn, string $urlId, WC_Order $localeContext): string
    {
        return sprintf(
            '%s/%s/r/%s/%s',
            $this->host(),
            rawurlencode($this->locale($localeContext)),
            rawurlencode($crn),
            rawurlencode($urlId),
        );
    }

    /**
     * Strip the API path off the configured base URL to get the host
     * the receipt viewer is served from. `https://vcr.am/api/v1` →
     * `https://vcr.am`; `http://localhost:3000/api/v1` →
     * `http://localhost:3000`.
     *
     * Falls back to the configured base URL verbatim if it can't be
     * parsed — better than silently returning an empty string and
     * generating broken `/<locale>/r/...` links.
     */
    public function host(): string
    {
        $apiBase = $this->config->baseUrl();
        $parts = parse_url($apiBase);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            // Garbled config — let the filter still get a crack at it,
            // but don't pretend we can derive a meaningful host.
            $derived = $apiBase;
        } else {
            $derived = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $derived .= ':' . $parts['port'];
            }
        }

        $filtered = apply_filters('vcr_receipt_host', $derived, $apiBase);

        return is_string($filtered) && $filtered !== '' ? $filtered : $derived;
    }

    /**
     * Pick the URL locale segment for a given order.
     *
     * Default source is the WP site locale via {@see get_locale()}. To
     * preserve the *customer's* checkout language across plugins like
     * WPML / Polylang (each of which uses its own meta key), stores can
     * hook the `vcr_receipt_locale` filter and return the right per-order
     * locale string.
     *
     * Always returns one of the three locales the receipt viewer ships:
     * `hy`, `ru`, `en`. Unknown input → `hy` (Armenia-first default).
     */
    private function locale(WC_Order $order): string
    {
        $siteLocale = function_exists('get_locale') ? get_locale() : '';
        $rawLocale = is_string($siteLocale) ? $siteLocale : '';

        $filtered = apply_filters('vcr_receipt_locale', $rawLocale, $order);
        if (is_string($filtered) && $filtered !== '') {
            $rawLocale = $filtered;
        }

        return self::normaliseLocale($rawLocale);
    }

    /**
     * Map any WP locale string (`hy_AM`, `ru_RU`, `en_US`, plain `en`)
     * to one of the three viewer locales. Unknown → `hy` (Armenia-first).
     */
    private static function normaliseLocale(string $locale): string
    {
        $prefix = strtolower(substr($locale, 0, 2));

        return match ($prefix) {
            'ru' => 'ru',
            'en' => 'en',
            default => 'hy',
        };
    }
}
