<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Currency\CbaExchangeRateProvider;
use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;
use Brain\Monkey\Functions;

/**
 * Sample CBA SOAP response for ExchangeRatesByDateAndISO with USD.
 * Trimmed from a real production response (May 2026 archive).
 */
function cbaSoapResponse(string $iso = 'USD', string $rate = '388.5', string $amount = '1', string $date = '2026-05-05T00:00:00'): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
        . ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
        . ' xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<soap:Body>'
        . '<ExchangeRatesByDateAndISOResponse xmlns="http://www.cba.am/">'
        . '<ExchangeRatesByDateAndISOResult>'
        . '<CurrentDate>' . $date . '</CurrentDate>'
        . '<Rates>'
        . '<ExchangeRate>'
        . '<ISO>' . $iso . '</ISO>'
        . '<Amount>' . $amount . '</Amount>'
        . '<Rate>' . $rate . '</Rate>'
        . '<Difference>0.5</Difference>'
        . '</ExchangeRate>'
        . '</Rates>'
        . '</ExchangeRatesByDateAndISOResult>'
        . '</ExchangeRatesByDateAndISOResponse>'
        . '</soap:Body>'
        . '</soap:Envelope>';
}

function stubWpHttp(int $statusCode, string $body): void
{
    Functions\when('wp_remote_post')->justReturn(['response' => ['code' => $statusCode], 'body' => $body]);
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $r): int => $r['response']['code']);
    Functions\when('wp_remote_retrieve_body')->alias(fn (array $r): string => $r['body']);
    Functions\when('esc_html')->returnArg(1);
}

beforeEach(function (): void {
    // Default-allow apply_filters returning the unchanged value (Brain
    // Monkey supplies this out of the box, but we lean on it for the
    // `vcr_cba_endpoint` filter the provider exposes).
});

it('parses a well-formed CBA response into an ExchangeRate', function (): void {
    stubWpHttp(200, cbaSoapResponse(iso: 'USD', rate: '388.5', amount: '1'));

    $rate = (new CbaExchangeRateProvider())->getRate('USD');

    expect($rate->iso)->toBe('USD')
        ->and($rate->rate)->toBe(388.5)
        ->and($rate->amount)->toBe(1.0)
        ->and($rate->publishedAt)->toBeGreaterThan(0);
});

it('handles multi-unit lots (JPY quoted per 100)', function (): void {
    stubWpHttp(200, cbaSoapResponse(iso: 'JPY', rate: '251.6', amount: '100'));

    $rate = (new CbaExchangeRateProvider())->getRate('JPY');

    expect($rate->amount)->toBe(100.0)
        ->and($rate->rate)->toBe(251.6)
        ->and($rate->unitToAmd())->toBe(2.516);
});

it('uppercases and trims the requested ISO before comparison', function (): void {
    stubWpHttp(200, cbaSoapResponse(iso: 'EUR', rate: '423.5', amount: '1'));

    $rate = (new CbaExchangeRateProvider())->getRate('  eur  ');

    expect($rate->iso)->toBe('EUR');
});

it('rejects malformed ISO codes before any HTTP call', function (): void {
    Functions\expect('wp_remote_post')->never();

    expect(fn () => (new CbaExchangeRateProvider())->getRate('XX'))
        ->toThrow(ExchangeRateUnavailableException::class, 'invalid ISO');
});

it('rejects empty ISO before any HTTP call', function (): void {
    Functions\expect('wp_remote_post')->never();

    expect(fn () => (new CbaExchangeRateProvider())->getRate(''))
        ->toThrow(ExchangeRateUnavailableException::class, 'invalid ISO');
});

it('throws when wp_remote_post returns a WP_Error', function (): void {
    Functions\when('wp_remote_post')->justReturn('whatever');
    Functions\when('is_wp_error')->justReturn(true);
    // Provide a minimal WP_Error stand-in.
    Functions\when('wp_remote_retrieve_response_code')->justReturn(0);
    Functions\when('wp_remote_retrieve_body')->justReturn('');
    Functions\when('esc_html')->returnArg(1);

    // The provider asks $error->get_error_message(); WP_Error in tests
    // doesn't exist, so we mock the same shape via an anonymous object.
    Functions\when('wp_remote_post')->justReturn(new class () {
        public function get_error_message(): string
        {
            return 'cURL error 28: timeout';
        }
    });

    expect(fn () => (new CbaExchangeRateProvider())->getRate('USD'))
        ->toThrow(ExchangeRateUnavailableException::class, 'CBA request failed');
});

it('throws on HTTP non-200 response codes', function (): void {
    stubWpHttp(503, '<html>Service unavailable</html>');

    expect(fn () => (new CbaExchangeRateProvider())->getRate('USD'))
        ->toThrow(ExchangeRateUnavailableException::class, 'HTTP 503');
});

it('throws on empty body even with HTTP 200', function (): void {
    stubWpHttp(200, '');

    expect(fn () => (new CbaExchangeRateProvider())->getRate('USD'))
        ->toThrow(ExchangeRateUnavailableException::class, 'empty body');
});

it('throws on malformed XML payload', function (): void {
    stubWpHttp(200, '<not-xml>>>>');

    expect(fn () => (new CbaExchangeRateProvider())->getRate('USD'))
        ->toThrow(ExchangeRateUnavailableException::class);
});

it('throws when CBA returns a different ISO than requested', function (): void {
    stubWpHttp(200, cbaSoapResponse(iso: 'EUR', rate: '423.5', amount: '1'));

    expect(fn () => (new CbaExchangeRateProvider())->getRate('USD'))
        ->toThrow(ExchangeRateUnavailableException::class, 'rate for "EUR" but we asked for "USD"');
});

it('throws when CBA returns no <ExchangeRate> nodes (unknown currency)', function (): void {
    stubWpHttp(
        200,
        '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<soap:Body><ExchangeRatesByDateAndISOResponse xmlns="http://www.cba.am/">'
        . '<ExchangeRatesByDateAndISOResult><Rates></Rates></ExchangeRatesByDateAndISOResult>'
        . '</ExchangeRatesByDateAndISOResponse></soap:Body></soap:Envelope>'
    );

    expect(fn () => (new CbaExchangeRateProvider())->getRate('XYZ'))
        ->toThrow(ExchangeRateUnavailableException::class, 'no <ExchangeRate>');
});

it('throws when CBA returns a non-positive rate', function (): void {
    stubWpHttp(200, cbaSoapResponse(iso: 'USD', rate: '0', amount: '1'));

    expect(fn () => (new CbaExchangeRateProvider())->getRate('USD'))
        ->toThrow(ExchangeRateUnavailableException::class, 'non-positive rate');
});

it('throws when CBA returns a non-positive amount', function (): void {
    stubWpHttp(200, cbaSoapResponse(iso: 'USD', rate: '388.5', amount: '0'));

    expect(fn () => (new CbaExchangeRateProvider())->getRate('USD'))
        ->toThrow(ExchangeRateUnavailableException::class, 'non-positive rate');
});

it('respects the vcr_cba_endpoint filter override', function (): void {
    Functions\when('apply_filters')
        ->alias(fn (string $hook, mixed $value, mixed ...$args): mixed =>
            $hook === 'vcr_cba_endpoint' ? 'https://override.example/cba.asmx' : $value);

    Functions\expect('wp_remote_post')
        ->once()
        ->with('https://override.example/cba.asmx', Mockery::any())
        ->andReturn(['response' => ['code' => 200], 'body' => cbaSoapResponse()]);
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $r): int => $r['response']['code']);
    Functions\when('wp_remote_retrieve_body')->alias(fn (array $r): string => $r['body']);
    Functions\when('esc_html')->returnArg(1);

    (new CbaExchangeRateProvider())->getRate('USD');
});
