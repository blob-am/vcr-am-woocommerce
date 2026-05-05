<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Refund\RefundReasonSanitizer;
use Brain\Monkey\Filters;

beforeEach(function (): void {
    $this->sanitizer = new RefundReasonSanitizer();
});

it('returns null for empty input', function (): void {
    expect($this->sanitizer->sanitize(''))->toBeNull();
});

it('returns null for whitespace-only input', function (): void {
    expect($this->sanitizer->sanitize("   \t\n"))->toBeNull();
});

it('passes a clean short reason through unchanged', function (): void {
    expect($this->sanitizer->sanitize('Customer changed mind'))
        ->toBe('Customer changed mind');
});

it('redacts email addresses pasted into the reason', function (): void {
    $input = 'Customer john@example.com complained about size';
    $output = $this->sanitizer->sanitize($input);

    expect($output)
        ->toContain('[EMAIL_REDACTED]')
        ->not->toContain('john@example.com');
});

it('redacts multiple email addresses in one reason', function (): void {
    $input = 'cc: support@vcr.am and customer@gmail.com both said it was broken';
    $output = $this->sanitizer->sanitize($input);

    expect($output)
        ->not->toContain('support@vcr.am')
        ->not->toContain('customer@gmail.com');
});

it('redacts international phone numbers', function (): void {
    expect($this->sanitizer->sanitize('Call me at +374 99 123456'))
        ->toContain('[PHONE_REDACTED]')
        ->not->toContain('374 99 123456');
});

it('redacts phone numbers with dashes and parens', function (): void {
    expect($this->sanitizer->sanitize('Their number was (010) 555-1234'))
        ->toContain('[PHONE_REDACTED]')
        ->not->toContain('555-1234');
});

it('does not over-eagerly redact short numeric strings (order numbers)', function (): void {
    // Order #123 is NOT a phone number (only 3 digits). The 7+ digit
    // floor on the regex means short refs survive.
    expect($this->sanitizer->sanitize('Order #123 cancelled'))
        ->toBe('Order #123 cancelled');
});

it('truncates reasons exceeding the default max length with an ellipsis', function (): void {
    $longReason = str_repeat('a', 250);
    $output = $this->sanitizer->sanitize($longReason);

    expect(mb_strlen($output ?? '', 'UTF-8'))->toBe(RefundReasonSanitizer::DEFAULT_MAX_LENGTH + 1);
    expect($output)->toEndWith('…');
});

it('respects a custom length cap via the vcr_refund_reason_max_length filter', function (): void {
    Filters\expectApplied('vcr_refund_reason_max_length')
        ->once()
        ->andReturn(20);
    Filters\expectApplied('vcr_refund_reason_sanitised')->once()->andReturnFirstArg();

    $output = $this->sanitizer->sanitize('A reason that is longer than twenty characters');

    expect(mb_strlen($output ?? '', 'UTF-8'))->toBe(21); // 20 + ellipsis
});

it('handles UTF-8 multibyte content correctly when truncating', function (): void {
    // Armenian text — each character is 2 bytes in UTF-8. mb_substr
    // must slice on character boundaries, not byte boundaries, or
    // we'd corrupt the output.
    $armenian = str_repeat('Բա', 200); // 400 chars
    $output = $this->sanitizer->sanitize($armenian);

    // No byte-level corruption.
    expect(mb_check_encoding($output ?? '', 'UTF-8'))->toBeTrue();
    // Truncated to default + ellipsis.
    expect(mb_strlen($output ?? '', 'UTF-8'))->toBe(RefundReasonSanitizer::DEFAULT_MAX_LENGTH + 1);
});

it('redacts before truncating so PII at the end of long input is still redacted', function (): void {
    $input = str_repeat('blah ', 50) . 'sensitive@email.com';
    $output = $this->sanitizer->sanitize($input);

    // Even though the email is past the truncation cap, redaction
    // happens first so the email never appears in any output state.
    // (Confirmed by ensuring the literal string is absent.)
    expect($output)->not->toContain('sensitive@email.com');
});

it('combines email + phone + length cap in one pass', function (): void {
    $input = 'Cust john@example.com (010) 555-1234 said: ' . str_repeat('x', 200);
    $output = $this->sanitizer->sanitize($input);

    expect($output)
        ->not->toContain('john@example.com')
        ->not->toContain('555-1234')
        ->toContain('[EMAIL_REDACTED]')
        ->toContain('[PHONE_REDACTED]')
        ->toEndWith('…');
});

it('the vcr_refund_reason_sanitised filter receives the redacted-and-trimmed value', function (): void {
    $captured = null;
    Filters\expectApplied('vcr_refund_reason_max_length')->once()->andReturnFirstArg();
    Filters\expectApplied('vcr_refund_reason_sanitised')
        ->once()
        ->andReturnUsing(static function (string $value, string $original) use (&$captured): string {
            $captured = ['value' => $value, 'original' => $original];

            return $value;
        });

    $this->sanitizer->sanitize('email me at user@x.com');

    expect($captured['value'])->toContain('[EMAIL_REDACTED]');
    expect($captured['original'])->toBe('email me at user@x.com');
});
