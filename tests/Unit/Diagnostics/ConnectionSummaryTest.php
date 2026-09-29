<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionFailure;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProblem;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionState;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionSummary;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\RegisterIdentity;

/**
 * One scannable line for the surfaces that report rather than instruct: the
 * WooCommerce status report and `wp vcr status`, both of which get pasted
 * into support tickets. It replaced a "Test mode: Disabled" row that
 * reported a plugin option nothing read.
 */
it('names the register, its mode and its owner', function (): void {
    $line = (new ConnectionSummary())->line(ConnectionState::connected(new RegisterIdentity(
        vcrId: 90,
        crn: '99123456',
        isSandbox: true,
        tradingName: 'Kravec Store',
        entityName: 'Kravec LLC',
        tin: '01234567',
    )));

    expect($line)->toBe('#90 (sandbox) — Kravec LLC, TIN 01234567');
});

it('says production when it is production', function (): void {
    $line = (new ConnectionSummary())->line(ConnectionState::connected(new RegisterIdentity(
        vcrId: 64,
        crn: '52470004',
        isSandbox: false,
        tradingName: 'Bakery',
        entityName: 'Bakery LLC',
        tin: '99887766',
    )));

    expect($line)->toContain('(production)');
});

it('gives every failure its own short label', function (): void {
    $summary = new ConnectionSummary();

    $lines = [];
    foreach (ConnectionProblem::cases() as $problem) {
        $lines[$problem->name] = $summary->line(
            ConnectionState::failed(new ConnectionFailure($problem)),
        );
    }

    expect($lines['NoApiKey'])->toBe('No API key saved')
        ->and($lines['Unreachable'])->toContain('unreachable')
        ->and($lines['KeyRejected'])->toContain('rejected')
        ->and($lines['RegisterNotActivated'])->toContain('Not activated')
        // Every case distinct: a report where two different faults read the
        // same is a report that cannot be triaged.
        ->and(array_unique(array_values($lines)))->toHaveCount(count($lines));
});
