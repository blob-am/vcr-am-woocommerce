<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Logging\Logger;
use Brain\Monkey\Functions;

it('no-ops when wc_get_logger is not available', function (): void {
    // Brain Monkey doesn't auto-stub `wc_get_logger`, so without an
    // explicit `when`, the function does not exist. Logger must
    // handle that gracefully — the unit test environment has no WC.
    expect(fn () => (new Logger())->info('does not crash'))->not->toThrow(Throwable::class);
});

it('routes info() through wc_get_logger with source: vcr', function (): void {
    $captured = null;

    $loggerMock = new class () {
        public ?array $lastCall = null;

        public function log(string $level, string $message, array $context): void
        {
            $this->lastCall = compact('level', 'message', 'context');
        }
    };

    Functions\when('wc_get_logger')->justReturn($loggerMock);

    (new Logger())->info('hello world');

    expect($loggerMock->lastCall)->toMatchArray([
        'level' => 'info',
        'message' => 'hello world',
    ]);
    expect($loggerMock->lastCall['context'])->toMatchArray(['source' => 'vcr']);
});

it('routes warning() at warning level', function (): void {
    $loggerMock = new class () {
        public ?string $level = null;

        public function log(string $level, string $message, array $context): void
        {
            $this->level = $level;
        }
    };

    Functions\when('wc_get_logger')->justReturn($loggerMock);

    (new Logger())->warning('something off');

    expect($loggerMock->level)->toBe('warning');
});

it('routes error() at error level', function (): void {
    $loggerMock = new class () {
        public ?string $level = null;

        public function log(string $level, string $message, array $context): void
        {
            $this->level = $level;
        }
    };

    Functions\when('wc_get_logger')->justReturn($loggerMock);

    (new Logger())->error('something broke');

    expect($loggerMock->level)->toBe('error');
});

it('merges caller-supplied context with the source key', function (): void {
    $loggerMock = new class () {
        public ?array $context = null;

        public function log(string $level, string $message, array $context): void
        {
            $this->context = $context;
        }
    };

    Functions\when('wc_get_logger')->justReturn($loggerMock);

    (new Logger())->info('with context', ['order_id' => 42, 'attempt' => 3]);

    expect($loggerMock->context)->toBe([
        'source' => 'vcr',
        'order_id' => 42,
        'attempt' => 3,
    ]);
});

it('exposes SOURCE constant for callers that want to filter by it', function (): void {
    expect(Logger::SOURCE)->toBe('vcr');
});
