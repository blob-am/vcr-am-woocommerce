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

// ---------- PII redaction (Phase B.7) ----------

function captureLog(): object
{
    $mock = new class () {
        public ?string $message = null;

        public ?array $context = null;

        public function log(string $level, string $message, array $context): void
        {
            $this->message = $message;
            $this->context = $context;
        }
    };

    Functions\when('wc_get_logger')->justReturn($mock);

    return $mock;
}

it('redacts known PII keys (billing_email) from context', function (): void {
    $mock = captureLog();

    (new Logger())->info('order 42 done', [
        'order_id' => 42,
        'billing_email' => 'customer@example.com',
    ]);

    expect($mock->context)->toMatchArray([
        'order_id' => 42,
        'billing_email' => '[REDACTED]',
    ]);
});

it('redacts the entire WC billing-key family case-insensitively', function (): void {
    $mock = captureLog();

    (new Logger())->info('with PII', [
        'BILLING_PHONE' => '+374 99 123456',
        'billing_first_name' => 'Anna',
        'billing_address_1' => '12 Mashtots Ave',
        'order_id' => 7,
    ]);

    expect($mock->context['BILLING_PHONE'])->toBe('[REDACTED]');
    expect($mock->context['billing_first_name'])->toBe('[REDACTED]');
    expect($mock->context['billing_address_1'])->toBe('[REDACTED]');
    expect($mock->context['order_id'])->toBe(7);
});

it('redacts free-form email addresses appearing in string values', function (): void {
    $mock = captureLog();

    (new Logger())->error('SDK exception', [
        'apiErrorMessage' => 'Receipt invalid for customer fred@x.com on attempt 3',
    ]);

    expect($mock->context['apiErrorMessage'])
        ->toContain('[EMAIL_REDACTED]')
        ->not->toContain('fred@x.com');
});

it('redacts free-form phone numbers in string values', function (): void {
    $mock = captureLog();

    (new Logger())->error('SDK exception', [
        'apiErrorMessage' => 'Customer phone +374 99 555 12 34 invalid',
    ]);

    expect($mock->context['apiErrorMessage'])
        ->toContain('[PHONE_REDACTED]')
        ->not->toContain('555 12 34');
});

it('redacts PII from the log MESSAGE itself, not just context', function (): void {
    $mock = captureLog();

    (new Logger())->warning('Cust john@example.com complained');

    expect($mock->message)
        ->toContain('[EMAIL_REDACTED]')
        ->not->toContain('john@example.com');
});

it('recursively redacts nested context arrays (payload sub-arrays)', function (): void {
    $mock = captureLog();

    (new Logger())->info('payload trace', [
        'payload' => [
            'buyer' => ['email' => 'a@b.com', 'name' => 'Anna'],
            'amount' => 1500,
        ],
    ]);

    expect($mock->context['payload']['buyer']['email'])->toBe('[REDACTED]');
    expect($mock->context['payload']['buyer']['name'])->toBe('[REDACTED]');
    expect($mock->context['payload']['amount'])->toBe(1500);
});

it('passes int/bool/object values through unchanged', function (): void {
    $mock = captureLog();
    $obj = new stdClass();
    $obj->id = 7;

    (new Logger())->info('mixed types', [
        'count' => 42,
        'flag' => true,
        'thing' => $obj,
    ]);

    expect($mock->context['count'])->toBe(42);
    expect($mock->context['flag'])->toBeTrue();
    expect($mock->context['thing'])->toBe($obj);
});

it('keeps the source: vcr key in the redacted output', function (): void {
    $mock = captureLog();

    (new Logger())->info('test', ['billing_email' => 'leaked@x.com']);

    expect($mock->context)->toHaveKey('source');
    expect($mock->context['source'])->toBe('vcr');
});
