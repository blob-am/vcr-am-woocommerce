<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Currency\CachedExchangeRateProvider;
use BlobSolutions\WooCommerceVcrAm\Currency\Exception\ExchangeRateUnavailableException;
use BlobSolutions\WooCommerceVcrAm\Currency\ExchangeRate;
use BlobSolutions\WooCommerceVcrAm\Currency\ExchangeRateProvider;
use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use Brain\Monkey\Functions;

/**
 * Test scaffolding: a `CachedExchangeRateProvider` subclass with a
 * controllable wall-clock so freshness/staleness scenarios are
 * deterministic. Without this we'd be at the mercy of `time()`.
 */
function clockableCache(ExchangeRateProvider $upstream, int $now, ?Logger $logger = null): CachedExchangeRateProvider
{
    return new class ($upstream, $logger ?? new Logger(), $now) extends CachedExchangeRateProvider {
        public function __construct(ExchangeRateProvider $upstream, Logger $logger, private int $clock)
        {
            parent::__construct($upstream, $logger);
        }

        protected function now(): int
        {
            return $this->clock;
        }
    };
}

/**
 * In-memory ExchangeRateProvider — the cache decorator's only contract
 * is that the upstream returns ExchangeRate or throws.
 */
function controlledUpstream(?ExchangeRate $rate = null, ?Throwable $throw = null, int $expectedCalls = 0): ExchangeRateProvider
{
    return new class ($rate, $throw, $expectedCalls) implements ExchangeRateProvider {
        public int $callCount = 0;

        public function __construct(
            private ?ExchangeRate $rate,
            private ?Throwable $throw,
            public int $expectedCalls
        ) {
        }

        public function getRate(string $iso): ExchangeRate
        {
            $this->callCount++;
            if ($this->throw !== null) {
                throw $this->throw;
            }
            if ($this->rate === null) {
                throw new ExchangeRateUnavailableException('test: no rate stubbed');
            }

            return $this->rate;
        }
    };
}

beforeEach(function (): void {
    // Default: no cache hit. Per-test cases override.
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);

    // Logger is wrapped by tests/TestCase.php which already stubs
    // wc_get_logger; rely on that.
});

it('hits upstream and writes to cache when transient is empty', function (): void {
    $now = 1735689600; // 2025-01-01 00:00:00 UTC
    $rate = new ExchangeRate(iso: 'USD', rate: 388.5, amount: 1.0, publishedAt: $now - 3600);
    $upstream = controlledUpstream(rate: $rate);

    // Capture the set_transient payload via a closure so we can assert
    // on it after the fact. Brain Monkey's Functions\expect() conflicts
    // with the beforeEach's Functions\when() for the same name; this
    // pattern is simpler and as verifiable.
    $written = null;
    Functions\when('set_transient')->alias(static function (string $key, mixed $value, int $ttl) use (&$written): bool {
        $written = ['key' => $key, 'value' => $value, 'ttl' => $ttl];

        return true;
    });

    $cached = clockableCache($upstream, now: $now);
    $result = $cached->getRate('USD');

    expect($result->rate)->toBe(388.5)
        ->and($upstream->callCount)->toBe(1)
        ->and($written)->toBeArray()
        ->and($written['key'])->toBe('vcr_cba_rate_USD')
        ->and($written['value']['rate'])->toBe(388.5)
        ->and($written['value']['amount'])->toBe(1.0)
        ->and($written['value']['fetchedAt'])->toBe($now)
        ->and($written['ttl'])->toBeGreaterThan(0);
});

it('serves from cache without contacting upstream when fresh (<24h old)', function (): void {
    $now = 1735689600;
    $cachedAt = $now - 12 * 3600; // 12h ago — well within fresh window

    Functions\when('get_transient')->justReturn([
        'rate' => 388.5, 'amount' => 1.0, 'publishedAt' => $cachedAt, 'fetchedAt' => $cachedAt,
    ]);
    $writeCount = 0;
    Functions\when('set_transient')->alias(static function () use (&$writeCount): bool {
        $writeCount++;

        return true;
    });

    $upstream = controlledUpstream(throw: new RuntimeException('upstream must not be called'));

    $cached = clockableCache($upstream, now: $now);
    $result = $cached->getRate('USD');

    expect($result->rate)->toBe(388.5)
        ->and($upstream->callCount)->toBe(0)
        ->and($writeCount)->toBe(0);
});

it('refreshes from upstream in the warning window (24-48h)', function (): void {
    $now = 1735689600;
    $cachedAt = $now - 36 * 3600; // 36h ago — warning window

    Functions\when('get_transient')->justReturn([
        'rate' => 380.0, 'amount' => 1.0, 'publishedAt' => $cachedAt, 'fetchedAt' => $cachedAt,
    ]);
    $writeCount = 0;
    Functions\when('set_transient')->alias(static function () use (&$writeCount): bool {
        $writeCount++;

        return true;
    });

    $newRate = new ExchangeRate(iso: 'USD', rate: 390.0, amount: 1.0, publishedAt: $now);
    $upstream = controlledUpstream(rate: $newRate);

    $result = clockableCache($upstream, now: $now)->getRate('USD');

    // Fresh value wins on successful refresh — not the cached 380.
    expect($result->rate)->toBe(390.0)
        ->and($upstream->callCount)->toBe(1)
        ->and($writeCount)->toBe(1);
});

it('falls back to cached value when upstream fails in the warning window (<48h)', function (): void {
    $now = 1735689600;
    $cachedAt = $now - 30 * 3600; // 30h ago — warning window

    Functions\when('get_transient')->justReturn([
        'rate' => 380.0, 'amount' => 1.0, 'publishedAt' => $cachedAt, 'fetchedAt' => $cachedAt,
    ]);
    $writeCount = 0;
    Functions\when('set_transient')->alias(static function () use (&$writeCount): bool {
        $writeCount++;

        return true;
    });

    $upstream = controlledUpstream(throw: new ExchangeRateUnavailableException('CBA timeout'));

    $result = clockableCache($upstream, now: $now)->getRate('USD');

    // Stale-but-acceptable cache wins because the upstream refresh failed.
    // No new write — we kept the existing cache row.
    expect($result->rate)->toBe(380.0)
        ->and($upstream->callCount)->toBe(1)
        ->and($writeCount)->toBe(0);
});

it('throws when cache is past 48h AND upstream fails — refuses to fiscalize at unsafe rate', function (): void {
    $now = 1735689600;
    $cachedAt = $now - 50 * 3600; // 50h ago — beyond hard ceiling

    Functions\when('get_transient')->justReturn([
        'rate' => 380.0, 'amount' => 1.0, 'publishedAt' => $cachedAt, 'fetchedAt' => $cachedAt,
    ]);

    $upstream = controlledUpstream(throw: new ExchangeRateUnavailableException('CBA down'));

    expect(fn () => clockableCache($upstream, now: $now)->getRate('USD'))
        ->toThrow(ExchangeRateUnavailableException::class, '50h old');
});

it('throws when cache is missing AND upstream fails', function (): void {
    Functions\when('get_transient')->justReturn(false);

    $upstream = controlledUpstream(throw: new ExchangeRateUnavailableException('CBA timeout'));

    expect(fn () => clockableCache($upstream, now: 1735689600)->getRate('USD'))
        ->toThrow(ExchangeRateUnavailableException::class, 'no cached rate');
});

it('ignores malformed cache entries (missing keys, wrong types)', function (): void {
    // Garbage in transient row (perhaps left over from a buggy old
    // version) must NOT be served. The cache miss path runs upstream.
    Functions\when('get_transient')->justReturn(['rate' => 'not-a-number']);
    $writeCount = 0;
    Functions\when('set_transient')->alias(static function () use (&$writeCount): bool {
        $writeCount++;

        return true;
    });

    $rate = new ExchangeRate(iso: 'USD', rate: 388.5, amount: 1.0, publishedAt: 0);
    $upstream = controlledUpstream(rate: $rate);

    $result = clockableCache($upstream, now: 1735689600)->getRate('USD');

    expect($result->rate)->toBe(388.5)
        ->and($upstream->callCount)->toBe(1)
        ->and($writeCount)->toBe(1);
});

it('keys the transient by uppercased ISO so case variants share the cache row', function (): void {
    $readKey = null;
    Functions\when('get_transient')->alias(static function (string $key) use (&$readKey): false {
        $readKey = $key;

        return false;
    });
    Functions\when('set_transient')->justReturn(true);

    $rate = new ExchangeRate(iso: 'EUR', rate: 423.5, amount: 1.0, publishedAt: 0);
    $upstream = controlledUpstream(rate: $rate);

    clockableCache($upstream, now: 1735689600)->getRate('  eur  ');

    expect($readKey)->toBe('vcr_cba_rate_EUR');
});
