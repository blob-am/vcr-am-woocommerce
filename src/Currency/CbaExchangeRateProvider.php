<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Currency;

use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;
use SimpleXMLElement;
use Throwable;

/**
 * Live SOAP-over-HTTP client for the Central Bank of Armenia exchange-rate
 * service.
 *
 * Why a hand-rolled SOAP envelope (no `ext-soap`):
 *
 *   - `ext-soap` is not always installed (PHP installations on shared
 *     hosting frequently strip it). The plugin's `composer.json` does not
 *     require it because we don't want to fragment the PHP-version matrix.
 *   - The CBA endpoint exposes exactly two methods we care about, both
 *     trivial single-element envelopes. Hand-rolled XML keeps the
 *     dependency cost at zero and the test surface obvious.
 *
 * Why `wp_remote_post()` (not Guzzle):
 *
 *   - This is a non-SDK call. Following WP convention, plugin code that
 *     hits arbitrary URLs goes through the WP HTTP API so admins'
 *     `pre_http_request` filter, proxy settings, timeout overrides, and
 *     SSL CA bundle apply uniformly.
 *   - Avoids a second Guzzle client instance — the Guzzle client we
 *     have is configured for vcr.am with API-key headers; reusing it
 *     for CBA would require rebuilding it without those headers, which
 *     is more code than `wp_remote_post`.
 *
 * Endpoint:
 *   POST https://api.cba.am/exchangerates.asmx
 *   Content-Type: text/xml; charset=utf-8
 *   SOAPAction: "http://www.cba.am/ExchangeRatesByDateAndISO"
 *
 * The plugin always queries by today's date; CBA returns the most-recent
 * rate published on or before that date (so weekends and holidays
 * naturally fall back to Friday's rate).
 *
 * What this class does NOT do (intentionally):
 *
 *   - **No caching.** That's {@see CachedExchangeRateProvider}'s job.
 *     Composing them keeps each class focused.
 *   - **No retry.** Transient network failures are surfaced immediately
 *     as {@see ExchangeRateUnavailableException}; the cache decorator's
 *     fallback-to-stale-but-recent behaviour handles outages.
 *   - **No multi-currency batching.** CBA's `ExchangeRatesByDate` returns
 *     all rates in one call, but the provider interface is per-ISO; if
 *     batching becomes a measurable cost, the cache decorator should
 *     gain a "warm all known currencies in one upstream hit" method.
 */
class CbaExchangeRateProvider implements ExchangeRateProvider
{
    /** Production CBA endpoint. Filterable for testing only — see {@see self::endpoint()}. */
    public const ENDPOINT = 'https://api.cba.am/exchangerates.asmx';

    /** Hard ceiling on the SOAP request, well above CBA's typical sub-second response. */
    private const TIMEOUT_SECONDS = 8;

    /**
     * Return today's published rate for the given ISO currency.
     *
     * @throws ExchangeRateUnavailableException
     */
    public function getRate(string $iso): ExchangeRate
    {
        $iso = strtoupper(trim($iso));

        if ($iso === '' || strlen($iso) !== 3 || ! ctype_alpha($iso)) {
            throw new ExchangeRateUnavailableException(sprintf(
                'Refusing to query CBA for invalid ISO code "%s" — expected three letters.',
                $iso,
            ));
        }

        $envelope = $this->buildEnvelope($iso);
        $response = $this->postEnvelope($envelope);

        return $this->parseResponse($response, $iso);
    }

    /**
     * SOAP endpoint URL. Filtered through `vcr_cba_endpoint` so tests
     * (and the rare on-prem CBA mirror) can override without touching
     * the production constant.
     */
    protected function endpoint(): string
    {
        $filtered = apply_filters('vcr_cba_endpoint', self::ENDPOINT);
        $url = is_string($filtered) ? $filtered : self::ENDPOINT;

        return $url !== '' ? $url : self::ENDPOINT;
    }

    private function buildEnvelope(string $iso): string
    {
        // CBA's `ExchangeRatesByDateAndISO` accepts an ISO 8601 date.
        // Use today's date in the server's timezone — CBA publishes on
        // Yerevan time but accepts any date and returns the most recent
        // rate at-or-before. Accept clock skew within a day.
        $date = gmdate('Y-m-d\TH:i:s');

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            . ' xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<ExchangeRatesByDateAndISO xmlns="http://www.cba.am/">'
            . '<date>' . esc_html($date) . '</date>'
            . '<ISOCodes>' . esc_html($iso) . '</ISOCodes>'
            . '</ExchangeRatesByDateAndISO>'
            . '</soap:Body>'
            . '</soap:Envelope>';
    }

    /**
     * @throws ExchangeRateUnavailableException
     */
    private function postEnvelope(string $envelope): string
    {
        $response = wp_remote_post(
            $this->endpoint(),
            [
                'method' => 'POST',
                'timeout' => self::TIMEOUT_SECONDS,
                'redirection' => 3,
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => '"http://www.cba.am/ExchangeRatesByDateAndISO"',
                    'Accept' => 'text/xml',
                ],
                'body' => $envelope,
                // CBA serves an EV-issued cert; let WP's bundled CA chain verify.
                'sslverify' => true,
            ],
        );

        if (is_wp_error($response)) {
            throw new ExchangeRateUnavailableException(sprintf(
                'CBA request failed: %s',
                $response->get_error_message(),
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            throw new ExchangeRateUnavailableException(sprintf(
                'CBA returned HTTP %d. First 200 chars of body: %s',
                is_int($code) ? $code : 0,
                substr((string) $body, 0, 200),
            ));
        }

        if (! is_string($body) || $body === '') {
            throw new ExchangeRateUnavailableException('CBA returned an empty body for a 200 response.');
        }

        return $body;
    }

    /**
     * @throws ExchangeRateUnavailableException
     */
    private function parseResponse(string $body, string $expectedIso): ExchangeRate
    {
        // SimpleXMLElement throws on malformed XML when libxml internal
        // errors are off, so wrap broadly. We don't trust the upstream
        // payload — a CBA server-side hiccup that returns HTML must
        // surface as ExchangeRateUnavailableException, not a fatal.
        try {
            $previous = libxml_use_internal_errors(true);
            $xml = new SimpleXMLElement($body);
            libxml_use_internal_errors($previous);
        } catch (Throwable $e) {
            throw new ExchangeRateUnavailableException(sprintf(
                'CBA returned non-XML payload: %s',
                $e->getMessage(),
            ));
        }

        $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('cba', 'http://www.cba.am/');

        // Walk straight to the first <ExchangeRate> element regardless of
        // how the response wraps it. CBA's response shape:
        //   soap:Envelope/soap:Body/ExchangeRatesByDateAndISOResponse/
        //     ExchangeRatesByDateAndISOResult/CurrentDate
        //     ExchangeRatesByDateAndISOResult/Rates/ExchangeRate
        //
        // XPath without the cba: namespace prefix on the inner elements
        // because CBA serves them in the same namespace as the wrapper —
        // local-name() lets us match by element name regardless.
        $rateNodes = $xml->xpath('//*[local-name()="ExchangeRate"]');
        if ($rateNodes === false || $rateNodes === null) {
            $rateNodes = [];
        }

        if ($rateNodes === []) {
            throw new ExchangeRateUnavailableException(sprintf(
                'CBA response contained no <ExchangeRate> for ISO "%s". Likely an unknown currency. Response head: %s',
                $expectedIso,
                substr($body, 0, 200),
            ));
        }

        $first = $rateNodes[0];
        $iso = isset($first->ISO) ? strtoupper(trim((string) $first->ISO)) : '';
        $rate = isset($first->Rate) ? (float) $first->Rate : 0.0;
        $amount = isset($first->Amount) ? (float) $first->Amount : 0.0;

        if ($iso !== $expectedIso) {
            throw new ExchangeRateUnavailableException(sprintf(
                'CBA returned rate for "%s" but we asked for "%s".',
                $iso,
                $expectedIso,
            ));
        }

        if ($rate <= 0.0 || $amount <= 0.0) {
            throw new ExchangeRateUnavailableException(sprintf(
                'CBA returned non-positive rate (rate=%s amount=%s) for "%s".',
                (string) $rate,
                (string) $amount,
                $expectedIso,
            ));
        }

        // CBA's <CurrentDate> is the rate-effective date. Parse it for
        // the ExchangeRate value object so the cache layer can preserve
        // the upstream date even across cache replays.
        $currentDateRaw = (string) ($xml->xpath('//*[local-name()="CurrentDate"]')[0] ?? '');
        $publishedAt = $currentDateRaw !== '' ? (int) strtotime($currentDateRaw) : 0;
        if ($publishedAt <= 0) {
            $publishedAt = time();
        }

        return new ExchangeRate(
            iso: $iso,
            rate: $rate,
            amount: $amount,
            publishedAt: $publishedAt,
        );
    }
}
