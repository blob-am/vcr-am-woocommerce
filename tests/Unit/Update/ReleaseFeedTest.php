<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use BlobSolutions\WooCommerceVcrAm\Update\ReleaseFeed;
use Brain\Monkey\Functions;

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function feedResponseBody(array $overrides = []): array
{
    return array_merge([
        'name' => 'VCR — Fiscal Receipts for Armenia (eHDM)',
        'slug' => 'vcr-am-fiscal-receipts',
        'version' => '0.1.13',
        'url' => 'https://github.com/blob-am/vcr-am-woocommerce/releases/tag/v0.1.13',
        'package' => 'https://github.com/blob-am/vcr-am-woocommerce/releases/download/v0.1.13/vcr-am-fiscal-receipts.zip',
    ], $overrides);
}

/**
 * Answers one HTTP call with the given status and body, and records what was
 * written to the transient so a test can assert on caching without a database.
 *
 * @param array<string, mixed>|string $body
 * @param array<string, mixed>        $written
 */
function stubFeedRequest(int $status, $body, array &$written, int &$calls): void
{
    Functions\when('wp_remote_get')->alias(function () use (&$calls) {
        $calls++;

        return ['stubbed' => true];
    });
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
    Functions\when('wp_remote_retrieve_body')->justReturn(is_string($body) ? $body : (string) json_encode($body));
    Functions\when('set_transient')->alias(function (string $key, $value, int $ttl) use (&$written): bool {
        $written = ['key' => $key, 'value' => $value, 'ttl' => $ttl];

        return true;
    });
}

function silentLogger(): Logger
{
    $logger = Mockery::mock(Logger::class);
    $logger->allows('warning');
    $logger->allows('error');
    $logger->allows('info');

    return $logger;
}

beforeEach(function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);
    Functions\when('delete_transient')->justReturn(true);
});

it('reads the release the feed answers with', function (): void {
    $written = [];
    $calls = 0;
    stubFeedRequest(200, feedResponseBody(), $written, $calls);

    $release = (new ReleaseFeed(silentLogger()))->latest();

    expect($release?->version)->toBe('0.1.13')
        ->and($written['ttl'] ?? null)->toBe(DAY_IN_SECONDS);
});

// Both filters can fire on one admin page load, and the modal fires a third
// time on a screen the merchant opened on purpose.
it('asks once per request however many times it is read', function (): void {
    $written = [];
    $calls = 0;
    stubFeedRequest(200, feedResponseBody(), $written, $calls);

    $feed = new ReleaseFeed(silentLogger());
    $feed->latest();
    $feed->latest();
    $feed->latest();

    expect($calls)->toBe(1);
});

it('serves a cached body without asking again', function (): void {
    $calls = 0;
    Functions\when('wp_remote_get')->alias(function () use (&$calls) {
        $calls++;

        return [];
    });
    Functions\when('get_transient')->justReturn(feedResponseBody());

    $release = (new ReleaseFeed(silentLogger()))->latest();

    expect($release?->version)->toBe('0.1.13')
        ->and($calls)->toBe(0);
});

// A cached row has been sitting in the options table since the last fetch.
// Re-reading it through the same validation is what keeps a hand-edited option
// from reaching the installer.
it('refuses a cached body that is no longer a valid release', function (): void {
    Functions\when('wp_remote_get')->justReturn([]);
    Functions\when('get_transient')->justReturn(feedResponseBody([
        'package' => 'https://example.com/anything.zip',
    ]));

    expect((new ReleaseFeed(silentLogger()))->latest())->toBeNull();
});

describe('when the feed cannot answer', function (): void {
    it('offers nothing on an error status', function (): void {
        $written = [];
        $calls = 0;
        stubFeedRequest(503, '', $written, $calls);

        expect((new ReleaseFeed(silentLogger()))->latest())->toBeNull();
    });

    it('offers nothing when the host cannot be reached', function (): void {
        // The suite has no WP_Error; the one method the feed calls on it is
        // enough to stand in for the shape wp_remote_get returns on failure.
        Functions\when('wp_remote_get')->justReturn(new class () {
            public function get_error_message(): string
            {
                return 'timed out';
            }
        });
        Functions\when('is_wp_error')->justReturn(true);

        expect((new ReleaseFeed(silentLogger()))->latest())->toBeNull();
    });

    // An outage must not put a ten-second timeout in front of every admin page
    // view, but it must also not be remembered for a day.
    it('remembers the failure for an hour, not for a day', function (): void {
        $written = [];
        $calls = 0;
        stubFeedRequest(503, '', $written, $calls);

        (new ReleaseFeed(silentLogger()))->latest();

        expect($written['ttl'] ?? null)->toBe(HOUR_IN_SECONDS);
    });

    it('does not retry inside the hour it already failed in', function (): void {
        $calls = 0;
        Functions\when('wp_remote_get')->alias(function () use (&$calls) {
            $calls++;

            return [];
        });
        Functions\when('get_transient')->justReturn('unavailable');

        expect((new ReleaseFeed(silentLogger()))->latest())->toBeNull()
            ->and($calls)->toBe(0);
    });

    it('offers nothing when the body is not JSON', function (): void {
        $written = [];
        $calls = 0;
        stubFeedRequest(200, '<html>maintenance</html>', $written, $calls);

        expect((new ReleaseFeed(silentLogger()))->latest())->toBeNull();
    });

    it('says so in the log when the body is JSON but not a release', function (): void {
        $written = [];
        $calls = 0;
        stubFeedRequest(200, ['hello' => 'world'], $written, $calls);

        $logger = Mockery::mock(Logger::class);
        $logger->expects('warning')->once();

        expect((new ReleaseFeed($logger))->latest())->toBeNull();
    });
});
