<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Pairing\FailureDetail;
use BlobSolutions\WooCommerceVcrAm\Pairing\PairingNotices;
use BlobSolutions\WooCommerceVcrAm\Pairing\SettingsUrl;
use Brain\Monkey\Functions;

/**
 * The only thing the connect flow says to a merchant out loud.
 *
 * Every other outcome of pairing is invisible: the key lands in an encrypted
 * option, the catalogs refresh themselves, and the code is stripped from the
 * address bar on the way here. If this renders the wrong sentence — or renders
 * a reassuring one for a pairing that never happened — nothing else corrects it.
 */
beforeEach(function (): void {
    $_GET = [];
    $this->store = [];

    Functions\when('sanitize_text_field')->returnArg(1);
    Functions\when('wp_unslash')->returnArg(1);
    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
        $this->store[$key] = $value;

        return true;
    });
    Functions\when('get_transient')->alias(fn (string $key): mixed => $this->store[$key] ?? false);
    Functions\when('delete_transient')->alias(function (string $key): bool {
        unset($this->store[$key]);

        return true;
    });
});

afterEach(function (): void {
    $_GET = [];
});

/**
 * @param array<string, string> $query
 */
function renderNoticesFor(array $query, ?FailureDetail $detail = null): string
{
    $_GET = $query;

    ob_start();
    (new PairingNotices($detail ?? new FailureDetail()))->render();

    return (string) ob_get_clean();
}

/**
 * The settings screen, which is where our own redirect always lands.
 *
 * @return array<string, string>
 */
function onSettingsScreen(string $marker): array
{
    return [
        'page' => 'wc-settings',
        'tab' => 'vcr',
        SettingsUrl::NOTICE_QUERY_PARAM => $marker,
    ];
}

it('reports a completed pairing as a success', function (): void {
    $output = renderNoticesFor(onSettingsScreen(SettingsUrl::NOTICE_CONNECTED));

    expect($output)->toContain('notice-success')
        ->and($output)->toContain('Connected');
});

it('reports a refusal without calling it an error', function (): void {
    // The merchant pressed "Cancel". Nothing went wrong, and telling them it
    // did would send them looking for a fault that is not there.
    $output = renderNoticesFor(onSettingsScreen(SettingsUrl::NOTICE_DENIED));

    expect($output)->toContain('notice-warning')
        ->and($output)->not->toContain('notice-error');
});

it('tells a merchant whose attempt expired to press the button again', function (): void {
    $output = renderNoticesFor(onSettingsScreen(SettingsUrl::NOTICE_EXPIRED));

    expect($output)->toContain('notice-warning')
        ->and($output)->toContain('again');
});

it('reports a failure as an error', function (): void {
    $output = renderNoticesFor(onSettingsScreen(SettingsUrl::NOTICE_FAILED));

    expect($output)->toContain('notice-error');
});

it('prints nothing for a marker it does not recognise', function (): void {
    expect(renderNoticesFor(onSettingsScreen('something-else')))->toBe('');
});

it('prints nothing when there is no marker at all', function (): void {
    expect(renderNoticesFor(['page' => 'wc-settings', 'tab' => 'vcr']))->toBe('');
});

it('prints nothing on any screen but its own', function (): void {
    // Otherwise a link somebody hands an administrator prints "Connected" on a
    // store that never paired, anywhere in wp-admin.
    $elsewhere = [SettingsUrl::NOTICE_QUERY_PARAM => SettingsUrl::NOTICE_CONNECTED];

    expect(renderNoticesFor($elsewhere))->toBe('');
    expect(renderNoticesFor($elsewhere + ['page' => 'wc-settings', 'tab' => 'shipping']))->toBe('');
    expect(renderNoticesFor($elsewhere + ['page' => 'wc-orders', 'tab' => 'vcr']))->toBe('');
});

it('adds the reason VCR.AM gave to a failure', function (): void {
    // The one failure a merchant will actually hit: VCR.AM refuses an http
    // redirectUri, so a shop whose wp-admin is not on https cannot pair. Without
    // this the notice says "check the error log" and they go looking for a
    // network fault they do not have.
    $detail = new FailureDetail();
    $detail->remember(7, 'redirectUri rejected (insecure_scheme): it must be an absolute https URL.');

    $output = renderNoticesFor(onSettingsScreen(SettingsUrl::NOTICE_FAILED), $detail);

    expect($output)->toContain('notice-error')
        ->and($output)->toContain('insecure_scheme');
});

it('shows a stored reason once and then forgets it', function (): void {
    $detail = new FailureDetail();
    $detail->remember(7, 'redirectUri rejected (insecure_scheme).');

    renderNoticesFor(onSettingsScreen(SettingsUrl::NOTICE_FAILED), $detail);
    $second = renderNoticesFor(onSettingsScreen(SettingsUrl::NOTICE_FAILED), $detail);

    expect($second)->toContain('notice-error')
        ->and($second)->not->toContain('insecure_scheme');
});

it('does not attach a stale reason to a success', function (): void {
    // A detail left by an abandoned attempt must not turn up next to
    // "Connected", which would read as a warning about the register just paired.
    $detail = new FailureDetail();
    $detail->remember(7, 'redirectUri rejected (insecure_scheme).');

    $output = renderNoticesFor(onSettingsScreen(SettingsUrl::NOTICE_CONNECTED), $detail);

    expect($output)->toContain('notice-success')
        ->and($output)->not->toContain('insecure_scheme');
});

it('keeps one user out of another user\'s failure reason', function (): void {
    $detail = new FailureDetail();
    $detail->remember(99, 'redirectUri rejected (insecure_scheme).');

    $output = renderNoticesFor(onSettingsScreen(SettingsUrl::NOTICE_FAILED), $detail);

    expect($output)->not->toContain('insecure_scheme');
});
