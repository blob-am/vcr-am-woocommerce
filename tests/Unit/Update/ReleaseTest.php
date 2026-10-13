<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Update\Release;

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function feedBody(array $overrides = []): array
{
    return array_merge([
        'name' => 'VCR — Fiscal Receipts for Armenia (eHDM)',
        'slug' => 'vcr-am-fiscal-receipts',
        'version' => '0.1.13',
        'url' => 'https://github.com/blob-am/vcr-am-woocommerce/releases/tag/v0.1.13',
        'package' => 'https://github.com/blob-am/vcr-am-woocommerce/releases/download/v0.1.13/vcr-am-fiscal-receipts.zip',
        'requires' => '6.7',
        'requires_php' => '8.3',
        'tested' => '7.1',
        'last_updated' => '2026-10-13 12:00:00',
        'sections' => ['changelog' => '<h4>0.1.13</h4>'],
    ], $overrides);
}

it('reads a well-formed feed', function (): void {
    $release = Release::fromFeed(feedBody());

    expect($release)->not->toBeNull()
        ->and($release?->version)->toBe('0.1.13')
        ->and($release?->requiresPhp)->toBe('8.3')
        ->and($release?->sections)->toBe(['changelog' => '<h4>0.1.13</h4>']);
});

it('refuses a feed that is not an object at all', function (): void {
    expect(Release::fromFeed('0.1.13'))->toBeNull();
});

it('refuses a feed missing a field the installer needs', function (string $field): void {
    $body = feedBody();
    unset($body[$field]);

    expect(Release::fromFeed($body))->toBeNull();
})->with(['version', 'package', 'url', 'slug', 'name']);

it('refuses an empty version rather than offering one', function (): void {
    expect(Release::fromFeed(feedBody(['version' => ''])))->toBeNull();
});

// The feed is a document fetched over the network that names code to unpack
// over a running plugin. These four are the cases where believing it would be
// how a store got something other than this plugin installed.
it('refuses a package hosted anywhere we do not publish from', function (): void {
    expect(Release::fromFeed(feedBody([
        'package' => 'https://example.com/vcr-am-fiscal-receipts.zip',
    ])))->toBeNull();
});

it('refuses a package served over plain http', function (): void {
    expect(Release::fromFeed(feedBody([
        'package' => 'http://github.com/blob-am/vcr-am-woocommerce/x.zip',
    ])))->toBeNull();
});

it('refuses a host that merely ends in an allowed one', function (): void {
    expect(Release::fromFeed(feedBody([
        'package' => 'https://notvcr.am/x.zip',
    ])))->toBeNull();
});

it('accepts a subdomain of a host we publish from', function (): void {
    $release = Release::fromFeed(feedBody([
        'package' => 'https://cdn.vcr.am/vcr-am-fiscal-receipts.zip',
    ]));

    expect($release?->package)->toBe('https://cdn.vcr.am/vcr-am-fiscal-receipts.zip');
});

it('drops section entries that are not strings', function (): void {
    $release = Release::fromFeed(feedBody([
        'sections' => ['changelog' => '<p>ok</p>', 'description' => ['nested'], 7 => 'unkeyed'],
    ]));

    expect($release?->sections)->toBe(['changelog' => '<p>ok</p>']);
});

describe('the update offer handed to core', function (): void {
    it('carries the version gates and the package', function (): void {
        $release = Release::fromFeed(feedBody());

        expect($release?->toUpdateOffer())->toBe([
            'slug' => 'vcr-am-fiscal-receipts',
            'version' => '0.1.13',
            'url' => 'https://github.com/blob-am/vcr-am-woocommerce/releases/tag/v0.1.13',
            'package' => 'https://github.com/blob-am/vcr-am-woocommerce/releases/download/v0.1.13/vcr-am-fiscal-receipts.zip',
            'requires' => '6.7',
            'requires_php' => '8.3',
            'tested' => '7.1',
        ]);
    });

    // Core stores whatever comes back in a site transient, on every site, for
    // as long as the plugin is installed. The changelog is 14 KB of HTML and
    // is only ever read by the modal, which asks for it separately.
    it('leaves the readme sections out', function (): void {
        $release = Release::fromFeed(feedBody());

        expect($release?->toUpdateOffer())->not->toHaveKey('sections');
    });

    it('omits a gate the feed did not state instead of sending an empty one', function (): void {
        $body = feedBody();
        unset($body['tested']);

        expect(Release::fromFeed($body)?->toUpdateOffer())->not->toHaveKey('tested');
    });
});

describe('what "Tested up to" means to the site reading it', function (): void {
    // Core compares the running version to this one with `<=`, so the bare
    // branch from the readme reads as "not tested" on every site past .0 and
    // prints a warning over the changelog. wordpress.org answers with the
    // running patch release instead; this matches that.
    it('reads the readme branch as covering the patch the site runs', function (): void {
        $release = Release::fromFeed(feedBody(['tested' => '7.1']))?->forWordPressVersion('7.1.3');

        expect($release?->tested)->toBe('7.1.3');
    });

    it('leaves a branch nobody tested against alone', function (): void {
        $release = Release::fromFeed(feedBody(['tested' => '7.1']))?->forWordPressVersion('7.2');

        expect($release?->tested)->toBe('7.1');
    });

    // 7.10 is not inside 7.1, and a string comparison is exactly how that
    // would go wrong.
    it('does not read 7.10 as part of the 7.1 line', function (): void {
        $release = Release::fromFeed(feedBody(['tested' => '7.1']))?->forWordPressVersion('7.10.2');

        expect($release?->tested)->toBe('7.1');
    });

    it('has nothing to widen when the feed stated no branch', function (): void {
        $body = feedBody();
        unset($body['tested']);

        expect(Release::fromFeed($body)?->forWordPressVersion('7.1.3')?->tested)->toBeNull();
    });
});
