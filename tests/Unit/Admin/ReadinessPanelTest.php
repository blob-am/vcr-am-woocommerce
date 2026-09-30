<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\ReadinessPanel;
use BlobSolutions\WooCommerceVcrAm\Catalog\CashierCatalog;
use BlobSolutions\WooCommerceVcrAm\Catalog\CatalogListing;
use BlobSolutions\WooCommerceVcrAm\Catalog\CatalogPolicy;
use BlobSolutions\WooCommerceVcrAm\Catalog\DepartmentCatalog;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionFailure;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProbe;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProblem;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionState;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\RegisterIdentity;
use Brain\Monkey\Functions;

/**
 * The checklist is the answer to the email this release was written for —
 * "no error, what parameters should I enter?" — so its wording is the
 * feature, and these tests are about wording. Each state has to say what is
 * true, what it means, and who does the next thing.
 *
 * The one sentence that must never come back is 0.1.6's "check your API key
 * permissions": it appeared for every empty dropdown, including under a
 * perfectly good key whose register simply had no cashiers, and it sent
 * people to inspect the one thing that was working.
 */
beforeEach(function (): void {
    Functions\when('admin_url')->alias(
        static fn (string $path = ''): string => 'https://shop.example/wp-admin/' . $path,
    );
    // Off by default so only the test that is about shipping renders the
    // shipping line.
    Functions\when('wc_shipping_enabled')->justReturn(false);
});

function panelIdentity(bool $isSandbox = false, ?string $crn = '52470004'): RegisterIdentity
{
    return new RegisterIdentity(
        vcrId: 90,
        crn: $crn,
        isSandbox: $isSandbox,
        tradingName: 'Kravec Store',
        entityName: 'Kravec LLC',
        tin: '01234567',
    );
}

/**
 * @param array<int, string> $cashiers
 * @param array<int, string> $departments
 */
function makePanel(
    ConnectionState $state,
    ?CatalogListing $cashiers = null,
    ?CatalogListing $departments = null,
    ?int $selectedCashier = null,
    ?int $departmentOverride = null,
    ?string $shippingSku = 'shipping',
    ?CatalogPolicy $policy = null,
): ReadinessPanel {
    $probe = Mockery::mock(ConnectionProbe::class);
    $probe->allows('state')->andReturn($state);

    $cashierCatalog = Mockery::mock(CashierCatalog::class);
    $cashierCatalog->allows('list')->andReturn($cashiers ?? CatalogListing::of([1 => '#1 (desk abc)']));

    $departmentCatalog = Mockery::mock(DepartmentCatalog::class);
    $departmentCatalog->allows('list')->andReturn($departments ?? CatalogListing::of([1 => 'VAT (#1)']));

    $config = Mockery::mock(Configuration::class);
    $config->allows('defaultCashierId')->andReturn($selectedCashier);
    $config->allows('defaultDepartmentId')->andReturn($departmentOverride);
    $config->allows('shippingSku')->andReturn($shippingSku);
    $config->allows('catalogPolicy')->andReturn(
        $policy ?? new CatalogPolicy(classifierCode: '56.10', departmentInternalId: 1),
    );

    return new ReadinessPanel($probe, $cashierCatalog, $departmentCatalog, $config);
}

it('reads green, and names the register, when everything is in place', function (): void {
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        selectedCashier: 1,
    )->render();

    expect($html)->toContain('notice-success')
        ->toContain('Kravec LLC')
        ->toContain('01234567')
        ->toContain('register #90')
        ->toContain('filed with the tax service')
        ->toContain('#1 (desk abc)');
});

it('warns that a sandbox register issues receipts with no legal force', function (): void {
    // A sandbox key looks exactly like a working one — the plugin reports
    // success, receipts appear, and nothing is filed with anyone. This is
    // also what replaced the "Test mode" checkbox: the register knows.
    $html = makePanel(
        ConnectionState::connected(panelIdentity(isSandbox: true)),
        selectedCashier: 1,
    )->render();

    expect($html)->toContain('notice-warning')
        ->toContain('sandbox register')
        ->toContain('no legal force');
});

it('blocks on a register that has no registration number yet', function (): void {
    $html = makePanel(
        ConnectionState::connected(panelIdentity(crn: null)),
        selectedCashier: 1,
    )->render();

    expect($html)->toContain('notice-error')
        ->toContain('no registration number');
});

it('says the register has no cashiers, and who can add one', function (): void {
    // The state entity 58 / VCR 90 was in on 2026-09-29: working key,
    // `GET /cashiers` -> 200 []. A register that becomes able to file is
    // given a cashier now, so reaching this state means an older register,
    // and every role on the business can add one — the integrator's too.
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        cashiers: CatalogListing::of([]),
    )->render();

    expect($html)->toContain('notice-error')
        ->toContain('no cashiers yet')
        ->toContain('developer accounts')
        ->toContain('vcr.am/dashboard')
        ->not->toContain('API key');
});

it('distinguishes "could not load the list" from "the list is empty"', function (): void {
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        cashiers: CatalogListing::unavailable(new ConnectionFailure(ConnectionProblem::Unexpected)),
        selectedCashier: 1,
    )->render();

    expect($html)->toContain('could not be loaded')
        ->not->toContain('no cashiers yet');
});

it('asks for a choice when the register has cashiers but none is picked', function (): void {
    $html = makePanel(ConnectionState::connected(panelIdentity()))->render();

    expect($html)->toContain('notice-error')
        ->toContain('Choose the cashier');
});

it('says so when the saved cashier is gone from the register', function (): void {
    // Receipts are being refused right now, and the number in the option
    // means nothing to the merchant unless we print it.
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        cashiers: CatalogListing::of([2 => '#2 (desk xyz)']),
        selectedCashier: 7,
    )->render();

    expect($html)->toContain('notice-error')
        ->toContain('#7')
        ->toContain('not on this register any more');
});

it('stays quiet about departments until there is something to say', function (): void {
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        selectedCashier: 1,
    )->render();

    expect($html)->not->toContain('Department');
});

it('spells out what a department override does, because nothing else shows it', function (): void {
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        departments: CatalogListing::of([4 => 'Micro-enterprise (#4)']),
        selectedCashier: 1,
        departmentOverride: 4,
    )->render();

    expect($html)->toContain('notice-warning')
        ->toContain('Micro-enterprise (#4)')
        ->toContain('ignoring the department each offer was registered with')
        ->toContain('only be refunded');
});

it('blocks when the saved department override is not on this register', function (): void {
    // Reachable in one click: "Reconnect to VCR.AM" is offered as the way to
    // move a store to a different register, and the override does not move with
    // it. The cashier step has said this for its own stale selection all along;
    // the override is the more expensive one, because the department decides the
    // tax regime printed on the receipt.
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        departments: CatalogListing::of([9 => 'VAT (#9)']),
        selectedCashier: 1,
        departmentOverride: 4,
    )->render();

    expect($html)->toContain('notice-error')
        ->toContain('#4')
        ->toContain('not on this register any more');
});

it('does not call an override stale when the department list could not be fetched', function (): void {
    // An unreachable API is not evidence that the saved department is gone, and
    // telling a merchant their receipts are refused when they are not would send
    // them changing settings that were correct.
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        departments: CatalogListing::unavailable(new ConnectionFailure(ConnectionProblem::Unreachable)),
        selectedCashier: 1,
        departmentOverride: 4,
    )->render();

    expect($html)->not->toContain('not on this register any more');
});

it('blocks when the register has no departments at all', function (): void {
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        departments: CatalogListing::of([]),
        selectedCashier: 1,
    )->render();

    expect($html)->toContain('notice-error')
        ->toContain('no departments');
});

it('warns a shipping store with no shipping SKU before its first delivery', function (): void {
    Functions\when('wc_shipping_enabled')->justReturn(true);

    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        selectedCashier: 1,
        shippingSku: null,
        policy: new CatalogPolicy(),
    )->render();

    expect($html)->toContain('notice-warning')
        ->toContain('Shipping is enabled')
        ->toContain('held for manual review');
});

it('stops warning about the shipping SKU once the plugin may create the line itself', function (): void {
    Functions\when('wc_shipping_enabled')->justReturn(true);

    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        selectedCashier: 1,
        shippingSku: null,
    )->render();

    expect($html)->not->toContain('Shipping is enabled');
});

it('says a newly added product would hold its own order while no code is set', function (): void {
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        selectedCashier: 1,
        policy: new CatalogPolicy(),
    )->render();

    expect($html)->toContain('notice-warning')
        ->toContain('Catalog')
        ->toContain('held for manual review')
        ->toContain('creates the item itself');
});

it('names the code new catalog items are filed under', function (): void {
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        selectedCashier: 1,
        policy: new CatalogPolicy(classifierCode: '01.11', departmentInternalId: 2),
    )->render();

    expect($html)->toContain('notice-success')
        ->toContain('<code>01.11</code>');
});

it('asks which department new items belong to when the register has several', function (): void {
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        departments: CatalogListing::of([1 => 'VAT (#1)', 2 => 'Turnover (#2)']),
        selectedCashier: 1,
        policy: new CatalogPolicy(classifierCode: '56.10'),
    )->render();

    expect($html)->toContain('notice-warning')
        ->toContain('more than one department')
        ->toContain('Department for new catalog items');
});

it('says nothing about shipping when the store does not ship', function (): void {
    $html = makePanel(
        ConnectionState::connected(panelIdentity()),
        selectedCashier: 1,
        shippingSku: null,
    )->render();

    expect($html)->not->toContain('Shipping is enabled');
});

it('starts a store with no key at the key, and nowhere else', function (): void {
    // Nothing below the connection is knowable yet, and inventing "no
    // cashier selected" on top of "no key" is how a first-run screen grows
    // three red lines that are all the same problem.
    $html = makePanel(ConnectionState::noApiKey())->render();

    expect($html)->toContain('notice-warning')
        ->toContain('No API key saved yet')
        ->toContain('vcr.am/dashboard')
        ->not->toContain('Cashier');
});

it('sends an unreachable host to the transport question, not to its credentials', function (): void {
    $html = makePanel(ConnectionState::failed(new ConnectionFailure(
        ConnectionProblem::Unreachable,
        technicalDetail: 'cURL error 7: Failed to connect',
    )))->render();

    expect($html)->toContain('notice-error')
        ->toContain('could not reach vcr.am')
        ->toContain('hosting firewall')
        // 0.1.6 added the HTTP-transport row to the status report for
        // exactly this case; link to it rather than describing it.
        ->toContain('page=wc-status')
        ->toContain('cURL error 7');
});

it('tells a rejected key from a register that never finished activation', function (): void {
    $rejected = makePanel(ConnectionState::failed(
        new ConnectionFailure(ConnectionProblem::KeyRejected),
    ))->render();

    $notActivated = makePanel(ConnectionState::failed(
        new ConnectionFailure(ConnectionProblem::RegisterNotActivated),
    ))->render();

    expect($rejected)->toContain('does not recognise this API key')
        ->and($notActivated)->toContain('has not finished activation')
        ->and($notActivated)->toContain('sandbox register');
});

it('quotes the request id on a server error, since that is the way in', function (): void {
    $html = makePanel(ConnectionState::failed(new ConnectionFailure(
        ConnectionProblem::ServerError,
        requestId: 'req_01HF',
    )))->render();

    expect($html)->toContain('notice-error')
        ->toContain('req_01HF');
});

it('escapes what the API gave it', function (): void {
    $hostile = new RegisterIdentity(
        vcrId: 7,
        crn: null,
        isSandbox: false,
        tradingName: '<script>alert(1)</script>',
        entityName: '<img onerror=alert(1)>',
        tin: '0',
    );

    $html = makePanel(ConnectionState::connected($hostile), selectedCashier: 1)->render();

    expect($html)->not->toContain('<script>')
        ->not->toContain('<img onerror');
});
