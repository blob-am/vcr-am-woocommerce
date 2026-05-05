<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Currency;

use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;
use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use Throwable;

/**
 * Decorator: adds a WP-transient cache + 48-hour staleness gate on top of
 * any underlying {@see ExchangeRateProvider}.
 *
 * Three-state freshness model:
 *
 *   - `< FRESH_THRESHOLD_HOURS` (default 24h) since `fetchedAt` ->
 *     return cache without contacting upstream. Most receipts in a
 *     production day go through this branch.
 *
 *   - `FRESH_THRESHOLD_HOURS .. STALE_THRESHOLD_HOURS` (24-48h) ->
 *     attempt upstream refresh. On success, replace cache and return
 *     fresh. On failure, **fall back to the cached value** (still
 *     within tolerable staleness) and log a warning. The fallback is
 *     why we don't use the transient TTL itself for staleness — TTL
 *     auto-deletes the entry, leaving us nothing to fall back to.
 *
 *   - `>= STALE_THRESHOLD_HOURS` (48h+) -> attempt upstream refresh.
 *     On success, replace cache. On failure, **throw** — refusing to
 *     fiscalise an order at a >48h stale rate is the readme's promise
 *     and the right compliance posture (incorrect tax data is worse
 *     than a deferred receipt).
 *
 * This shape is the same one Stripe / Razorpay / etc. use for FX
 * caching: aggressive cache hit, opportunistic refresh in the
 * "warning" zone, hard refusal once truly stale.
 *
 * Storage:
 *
 *   - One transient per ISO: `vcr_cba_rate_<ISO>`. Stored as an array
 *     `{rate: float, amount: float, publishedAt: int, fetchedAt: int}`.
 *   - Transient TTL is set to a generous 7 days so the row survives
 *     long-enough for the staleness gate to evaluate it. The gate is
 *     the load-bearing freshness check — TTL is just garbage collection.
 *   - WP transients can be backed by an external object cache
 *     (Memcached / Redis); this is fine — the entry is small and
 *     read-mostly.
 */
class CachedExchangeRateProvider implements ExchangeRateProvider
{
    private const FRESH_THRESHOLD_HOURS = 24;

    private const STALE_THRESHOLD_HOURS = 48;

    private const TRANSIENT_PREFIX = 'vcr_cba_rate_';

    private const TRANSIENT_TTL_SECONDS = 7 * 24 * 3600;

    public function __construct(
        private readonly ExchangeRateProvider $upstream,
        private readonly Logger $logger = new Logger(),
    ) {
    }

    public function getRate(string $iso): ExchangeRate
    {
        $iso = strtoupper(trim($iso));
        $cached = $this->readCache($iso);
        $now = $this->now();

        if ($cached !== null && ($now - $cached['fetchedAt']) < self::FRESH_THRESHOLD_HOURS * 3600) {
            return $this->materialise($iso, $cached);
        }

        // Either no cache or in the "warning" / "stale" window. Try
        // upstream regardless — a successful refresh trumps every
        // cached value.
        try {
            $fresh = $this->upstream->getRate($iso);
            $this->writeCache($iso, $fresh, $now);

            return $fresh;
        } catch (Throwable $e) {
            // Fallback path: cache may save us, but only if it's still
            // within the absolute 48-hour ceiling.
            if ($cached !== null && ($now - $cached['fetchedAt']) < self::STALE_THRESHOLD_HOURS * 3600) {
                $this->logger->warning(
                    sprintf(
                        'CBA refresh for %s failed (%s); serving cached rate from %d (age %dh).',
                        $iso,
                        $e->getMessage(),
                        $cached['fetchedAt'],
                        (int) (($now - $cached['fetchedAt']) / 3600),
                    ),
                    ['iso' => $iso, 'cached_at' => $cached['fetchedAt']],
                );

                return $this->materialise($iso, $cached);
            }

            // No cache, or cache too stale to use — surface the upstream
            // failure with extra context so the operator can tell the
            // difference between "CBA is down" and "we have no recent
            // data and we therefore can't fiscalise this order safely".
            throw new ExchangeRateUnavailableException(
                $cached === null
                    ? sprintf('CBA unreachable and no cached rate for %s: %s', $iso, $e->getMessage())
                    : sprintf(
                        'CBA unreachable and cached rate for %s is %dh old (>%dh limit): %s',
                        $iso,
                        (int) (($now - $cached['fetchedAt']) / 3600),
                        self::STALE_THRESHOLD_HOURS,
                        $e->getMessage(),
                    ),
                previous: $e,
            );
        }
    }

    /**
     * Wall-clock seconds. Extracted so tests can override for
     * deterministic staleness scenarios via subclass.
     */
    protected function now(): int
    {
        return time();
    }

    /**
     * @return ?array{rate: float, amount: float, publishedAt: int, fetchedAt: int}
     */
    private function readCache(string $iso): ?array
    {
        $raw = get_transient(self::TRANSIENT_PREFIX . $iso);
        if (! is_array($raw)) {
            return null;
        }

        $rate = $raw['rate'] ?? null;
        $amount = $raw['amount'] ?? null;
        $publishedAt = $raw['publishedAt'] ?? null;
        $fetchedAt = $raw['fetchedAt'] ?? null;

        if (
            ! is_numeric($rate) || ! is_numeric($amount)
            || ! is_int($publishedAt) || ! is_int($fetchedAt)
            || (float) $rate <= 0.0 || (float) $amount <= 0.0
        ) {
            return null;
        }

        return [
            'rate' => (float) $rate,
            'amount' => (float) $amount,
            'publishedAt' => $publishedAt,
            'fetchedAt' => $fetchedAt,
        ];
    }

    private function writeCache(string $iso, ExchangeRate $rate, int $now): void
    {
        set_transient(
            self::TRANSIENT_PREFIX . $iso,
            [
                'rate' => $rate->rate,
                'amount' => $rate->amount,
                'publishedAt' => $rate->publishedAt,
                'fetchedAt' => $now,
            ],
            self::TRANSIENT_TTL_SECONDS,
        );
    }

    /**
     * @param array{rate: float, amount: float, publishedAt: int, fetchedAt: int} $cached
     */
    private function materialise(string $iso, array $cached): ExchangeRate
    {
        return new ExchangeRate(
            iso: $iso,
            rate: $cached['rate'],
            amount: $cached['amount'],
            publishedAt: $cached['publishedAt'],
        );
    }
}
