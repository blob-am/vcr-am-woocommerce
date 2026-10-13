<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Update\Release;
use BlobSolutions\WooCommerceVcrAm\Update\ReleaseFeed;
use BlobSolutions\WooCommerceVcrAm\Update\UpdateChecker;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

const VCR_PLUGIN_FILE = '/srv/wp-content/plugins/vcr-am-fiscal-receipts/vcr-am-fiscal-receipts.php';
const VCR_PLUGIN_BASENAME = 'vcr-am-fiscal-receipts/vcr-am-fiscal-receipts.php';

beforeEach(function (): void {
    Functions\when('plugin_basename')->justReturn(VCR_PLUGIN_BASENAME);
    Functions\when('get_bloginfo')->justReturn('7.1.3');
});

function aRelease(): Release
{
    $release = Release::fromFeed([
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
    ]);

    if ($release === null) {
        throw new RuntimeException('fixture feed did not produce a release');
    }

    return $release;
}

function aChecker(?Release $release): UpdateChecker
{
    $feed = Mockery::mock(ReleaseFeed::class);
    $feed->allows('latest')->andReturn($release);

    return new UpdateChecker(VCR_PLUGIN_FILE, $feed);
}

// vcr.am is the hostname in UPDATE_URI, and core builds the hook name from
// the header the same way. A mismatch here is not an error anywhere: it is a
// plugin that is simply never asked, on every store, silently.
it('listens on the hook core derives from the Update URI header', function (): void {
    Filters\expectAdded('update_plugins_vcr.am')->once();
    Filters\expectAdded('plugins_api')->once();

    aChecker(aRelease())->register();
});

describe('the update offer', function (): void {
    it('answers for this plugin', function (): void {
        $offer = aChecker(aRelease())->offerUpdate(false, [], VCR_PLUGIN_BASENAME);

        expect($offer)->toBeArray()
            ->and($offer['version'] ?? null)->toBe('0.1.13')
            ->and($offer['package'] ?? null)->toContain('v0.1.13');
    });

    // The hook is keyed by hostname, so a second plugin updating from vcr.am
    // would be asked through the same filter.
    it('says nothing about another plugin', function (): void {
        $offer = aChecker(aRelease())->offerUpdate(false, [], 'something-else/something-else.php');

        expect($offer)->toBeFalse();
    });

    it('leaves an answer another filter already gave', function (): void {
        $existing = ['version' => '9.9.9'];
        $offer = aChecker(aRelease())->offerUpdate($existing, [], VCR_PLUGIN_BASENAME);

        expect($offer)->toBe($existing);
    });

    // A feed that is down, or answering with something unusable, must leave
    // the store exactly as it is.
    it('offers nothing when the feed has nothing', function (): void {
        expect(aChecker(null)->offerUpdate(false, [], VCR_PLUGIN_BASENAME))->toBeFalse();
    });
});

describe('the plugin-information modal', function (): void {
    beforeEach(function (): void {
        Functions\when('get_plugin_data')->justReturn(['Author' => 'Blob Solutions']);
    });

    it('describes the release behind the changelog link', function (): void {
        $info = aChecker(aRelease())->describeRelease(
            false,
            'plugin_information',
            (object) ['slug' => 'vcr-am-fiscal-receipts'],
        );

        expect($info)->toBeObject()
            ->and($info->version)->toBe('0.1.13')
            ->and($info->sections)->toBe(['changelog' => '<h4>0.1.13</h4>'])
            ->and($info->download_link)->toContain('v0.1.13')
            ->and($info->requires_php)->toBe('8.3')
            ->and($info->author)->toBe('Blob Solutions');
    });

    it('ignores a request about another plugin', function (): void {
        $info = aChecker(aRelease())->describeRelease(
            false,
            'plugin_information',
            (object) ['slug' => 'woocommerce'],
        );

        expect($info)->toBeFalse();
    });

    it('ignores the other things plugins_api is asked', function (): void {
        $info = aChecker(aRelease())->describeRelease(
            false,
            'query_plugins',
            (object) ['slug' => 'vcr-am-fiscal-receipts'],
        );

        expect($info)->toBeFalse();
    });

    it('stays out of the way when the feed has nothing', function (): void {
        $info = aChecker(null)->describeRelease(
            false,
            'plugin_information',
            (object) ['slug' => 'vcr-am-fiscal-receipts'],
        );

        expect($info)->toBeFalse();
    });
});
