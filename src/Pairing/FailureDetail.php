<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Pairing;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Why a pairing attempt failed, in words the merchant can act on.
 *
 * The outcome of a pairing travels back to the settings screen as a marker in
 * the query string, which is enough to pick one of four sentences but not to
 * say which of several things went wrong. For the most likely failure that
 * difference is everything: VCR.AM refuses a `redirectUri` that is not https,
 * and a shop whose wp-admin is served over plain http therefore cannot pair at
 * all. "Could not connect to VCR.AM" sends that merchant to their hosting
 * provider to debug a network problem they do not have.
 *
 * VCR.AM already answers with the sentence they need — "redirectUri rejected
 * (insecure_scheme): it must be an absolute https URL..." — so this carries it
 * across the redirect rather than the plugin restating the rule. Restating it
 * would put the same rule in two codebases, and the one in the plugin would be
 * the one that goes stale.
 *
 * Not declared `final` so unit tests can mock it; there is no production
 * extension point.
 */
class FailureDetail
{
    /**
     * Only has to outlive one redirect. Short so a detail from a failure the
     * merchant walked away from cannot resurface next to an unrelated one.
     */
    public const TTL_SECONDS = 2 * MINUTE_IN_SECONDS;

    private const TRANSIENT_PREFIX = 'vcr_pairing_detail_';

    /**
     * Cap on what gets stored. The message comes from the API, so it is not
     * merchant input, but it does end up on an admin screen: bound it here
     * rather than trusting the far end to stay terse.
     */
    private const MAX_LENGTH = 300;

    public function remember(int $userId, string $message): void
    {
        $trimmed = trim($message);

        if ($trimmed === '') {
            return;
        }

        set_transient(
            self::keyFor($userId),
            mb_substr($trimmed, 0, self::MAX_LENGTH),
            self::TTL_SECONDS,
        );
    }

    /**
     * Reads the detail and clears it, so it is shown once and does not attach
     * itself to the next attempt.
     */
    public function take(int $userId): ?string
    {
        $key = self::keyFor($userId);
        $stored = get_transient($key);
        delete_transient($key);

        if (! is_string($stored) || trim($stored) === '') {
            return null;
        }

        return $stored;
    }

    private static function keyFor(int $userId): string
    {
        return self::TRANSIENT_PREFIX . $userId;
    }
}
