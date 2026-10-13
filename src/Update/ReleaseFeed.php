<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Update;

use BlobSolutions\WooCommerceVcrAm\Logging\Logger;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads the latest release from vcr.am, cached.
 *
 * WordPress throttles its own update check, but the two filters that use this
 * can both fire within one admin page load, and the plugin-information modal
 * fires it again on a screen the merchant opened deliberately. A transient
 * keeps that to one request a day per store in the steady state.
 *
 * Failures are cached too, for a much shorter window. A feed that is down
 * should not have every admin page view in the outage wait on a timeout, and
 * an hour of not hearing about a new version costs nothing.
 *
 * Nothing here throws. This runs on `admin_init`-era hooks on every admin page
 * of the site; an exception escaping would take down the dashboard over an
 * update check, which is a far worse outcome than not knowing about a release.
 */
class ReleaseFeed
{
    public const FEED_URL = 'https://vcr.am/api/plugins/woocommerce/latest';

    private const TRANSIENT_KEY = 'vcr_latest_release';

    private const TTL_SECONDS = DAY_IN_SECONDS;

    private const FAILURE_TTL_SECONDS = HOUR_IN_SECONDS;

    /** Marks a cached failure apart from a cached release. */
    private const UNAVAILABLE = 'unavailable';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Set once per request after the first read, so the two filters that both
     * want the release do not each pay a transient round-trip.
     */
    private ?Release $memo = null;

    private bool $memoised = false;

    public function __construct(
        private readonly Logger $logger = new Logger(),
    ) {
    }

    public function latest(): ?Release
    {
        if ($this->memoised) {
            return $this->memo;
        }

        $this->memoised = true;
        $this->memo = $this->read();

        return $this->memo;
    }

    public function refresh(): void
    {
        delete_transient(self::TRANSIENT_KEY);
        $this->memo = null;
        $this->memoised = false;
    }

    private function read(): ?Release
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if ($cached === self::UNAVAILABLE) {
            return null;
        }
        if (is_array($cached)) {
            // Re-validated rather than trusted: the row has been sitting in
            // the database since the last fetch, and the only cost of asking
            // again is the one that keeps a hand-edited option from reaching
            // the installer.
            return Release::fromFeed($cached);
        }

        $decoded = $this->fetch();
        if ($decoded === null) {
            set_transient(self::TRANSIENT_KEY, self::UNAVAILABLE, self::FAILURE_TTL_SECONDS);

            return null;
        }

        $release = Release::fromFeed($decoded);
        if ($release === null) {
            $this->logger->warning('update feed returned something that is not a release');
            set_transient(self::TRANSIENT_KEY, self::UNAVAILABLE, self::FAILURE_TTL_SECONDS);

            return null;
        }

        set_transient(self::TRANSIENT_KEY, $decoded, self::TTL_SECONDS);

        return $release;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function fetch(): ?array
    {
        $response = wp_remote_get(self::FEED_URL, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            $this->logger->warning('could not reach the update feed', [
                'error' => $response->get_error_message(),
            ]);

            return null;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $this->logger->warning('update feed answered with an error', ['status' => $status]);

            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : null;
    }
}
