<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Tests\Unit\Fiscal;

use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalJobOutcome;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;

it('shouldRetry returns true only for retriable outcomes', function (): void {
    expect(FiscalJobOutcome::retriable('5xx')->shouldRetry())->toBeTrue()
        ->and(FiscalJobOutcome::success()->shouldRetry())->toBeFalse()
        ->and(FiscalJobOutcome::failed('4xx')->shouldRetry())->toBeFalse()
        ->and(FiscalJobOutcome::manualRequired('config')->shouldRetry())->toBeFalse();
});

it('preserves the reason for non-success outcomes', function (): void {
    expect(FiscalJobOutcome::retriable('boom')->reason)->toBe('boom')
        ->and(FiscalJobOutcome::failed('bad')->reason)->toBe('bad')
        ->and(FiscalJobOutcome::manualRequired('cfg')->reason)->toBe('cfg')
        ->and(FiscalJobOutcome::success()->reason)->toBeNull();
});

it('maps each factory to the matching FiscalStatus', function (): void {
    expect(FiscalJobOutcome::success()->status)->toBe(FiscalStatus::Success)
        ->and(FiscalJobOutcome::retriable('x')->status)->toBe(FiscalStatus::Pending)
        ->and(FiscalJobOutcome::failed('x')->status)->toBe(FiscalStatus::Failed)
        ->and(FiscalJobOutcome::manualRequired('x')->status)->toBe(FiscalStatus::ManualRequired);
});
