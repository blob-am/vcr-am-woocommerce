<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\OrdersListFilter;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatus;
use BlobSolutions\WooCommerceVcrAm\Fiscal\FiscalStatusMeta;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    Functions\when('wp_unslash')->returnArg();
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('selected')->alias(function ($current, $value, bool $echo = true): string {
        $out = $current === $value ? ' selected="selected"' : '';
        if ($echo) {
            echo $out;
        }

        return $out;
    });
    Functions\when('is_admin')->justReturn(true);
    $_GET = [];
});

afterEach(function (): void {
    $_GET = [];
});

function captureFilterOutput(callable $fn): string
{
    ob_start();
    $fn();

    return (string) ob_get_clean();
}

it('register hooks all four list-table integration points', function (): void {
    Actions\expectAdded('woocommerce_order_list_table_restrict_manage_orders')->once();
    Filters\expectAdded('woocommerce_order_list_table_prepare_items_query_args')->once();
    Actions\expectAdded('restrict_manage_posts')->once();
    Actions\expectAdded('pre_get_posts')->once();

    (new OrdersListFilter())->register();
});

it('renderDropdown emits the select with all five fiscal status options', function (): void {
    $html = captureFilterOutput(fn () => (new OrdersListFilter())->renderDropdown());

    expect($html)
        ->toContain('id="filter-by-vcr-fiscal-status"')
        ->toContain('All fiscal statuses')
        ->toContain('Not enqueued')
        ->toContain('Queued')
        ->toContain('Registered')
        ->toContain('Failed')
        ->toContain('Needs attention');
});

it('renderDropdown marks the current selection (read from $_GET)', function (): void {
    $_GET[OrdersListFilter::QUERY_PARAM] = 'failed';

    $html = captureFilterOutput(fn () => (new OrdersListFilter())->renderDropdown());

    // The "failed" option carries the selected attribute.
    expect($html)->toMatch('/<option value="failed"[^>]*selected/');
});

it('renderLegacyDropdown is gated on the shop_order post type screen', function (): void {
    // No post_type set → render nothing.
    $html = captureFilterOutput(fn () => (new OrdersListFilter())->renderLegacyDropdown());
    expect($html)->toBe('');

    // post_type=shop_order → renders.
    $_GET['post_type'] = 'shop_order';
    $html = captureFilterOutput(fn () => (new OrdersListFilter())->renderLegacyDropdown());
    expect($html)->toContain('All fiscal statuses');

    // post_type=page → no render (don't pollute unrelated screens).
    $_GET['post_type'] = 'page';
    $html = captureFilterOutput(fn () => (new OrdersListFilter())->renderLegacyDropdown());
    expect($html)->toBe('');
});

// ---------- HPOS query mod ----------

it('filterHposQuery returns args unchanged when no selection', function (): void {
    $args = ['status' => 'wc-processing'];
    $result = (new OrdersListFilter())->filterHposQuery($args);

    expect($result)->toBe(['status' => 'wc-processing']);
});

it('filterHposQuery injects meta_query for a valid status selection', function (): void {
    $_GET[OrdersListFilter::QUERY_PARAM] = FiscalStatus::Failed->value;

    $result = (new OrdersListFilter())->filterHposQuery(['status' => 'wc-processing']);

    expect($result['meta_query'])->toBe([
        [
            'key' => FiscalStatusMeta::META_STATUS,
            'value' => 'failed',
            'compare' => '=',
        ],
    ]);
});

it('filterHposQuery injects NOT EXISTS clause for the not_enqueued sentinel', function (): void {
    $_GET[OrdersListFilter::QUERY_PARAM] = OrdersListFilter::NOT_ENQUEUED;

    $result = (new OrdersListFilter())->filterHposQuery([]);

    expect($result['meta_query'])->toBe([
        [
            'key' => FiscalStatusMeta::META_STATUS,
            'compare' => 'NOT EXISTS',
        ],
    ]);
});

it('filterHposQuery rejects invalid status values (defends against URL tampering)', function (): void {
    $_GET[OrdersListFilter::QUERY_PARAM] = 'alien-state-no-such-thing';

    $result = (new OrdersListFilter())->filterHposQuery(['status' => 'wc-processing']);

    // No meta_query injected — invalid selection silently treated as "no filter".
    expect($result)->not->toHaveKey('meta_query');
});

it('filterHposQuery preserves an existing meta_query (other plugins\' filters)', function (): void {
    $_GET[OrdersListFilter::QUERY_PARAM] = FiscalStatus::Success->value;
    $existingMq = [['key' => 'something_else', 'value' => 'foo']];

    $result = (new OrdersListFilter())->filterHposQuery([
        'meta_query' => $existingMq,
    ]);

    expect($result['meta_query'])->toBe([
        'relation' => 'AND',
        $existingMq,
        ['key' => FiscalStatusMeta::META_STATUS, 'value' => 'success', 'compare' => '='],
    ]);
});

it('filterHposQuery handles non-array input defensively', function (): void {
    /** @phpstan-ignore-next-line — verifying defensive branch */
    $result = (new OrdersListFilter())->filterHposQuery(null);

    expect($result)->toBe([]);
});

// ---------- Legacy query mod ----------

it('filterLegacyQuery skips non-admin contexts', function (): void {
    Functions\when('is_admin')->justReturn(false);
    $_GET[OrdersListFilter::QUERY_PARAM] = 'failed';

    $query = Mockery::mock(WP_Query::class);
    $query->shouldNotReceive('set');

    (new OrdersListFilter())->filterLegacyQuery($query);
});

it('filterLegacyQuery skips non-main queries', function (): void {
    $_GET[OrdersListFilter::QUERY_PARAM] = 'failed';

    $query = Mockery::mock(WP_Query::class);
    $query->expects('is_main_query')->andReturn(false);
    $query->shouldNotReceive('set');

    (new OrdersListFilter())->filterLegacyQuery($query);
});

it('filterLegacyQuery skips non-shop_order post types', function (): void {
    $_GET[OrdersListFilter::QUERY_PARAM] = 'failed';

    $query = Mockery::mock(WP_Query::class);
    $query->expects('is_main_query')->andReturn(true);
    $query->expects('get')->with('post_type')->andReturn('post');
    $query->shouldNotReceive('set');

    (new OrdersListFilter())->filterLegacyQuery($query);
});

it('filterLegacyQuery sets meta_query when selection is valid', function (): void {
    $_GET[OrdersListFilter::QUERY_PARAM] = FiscalStatus::Failed->value;

    $query = Mockery::mock(WP_Query::class);
    $query->expects('is_main_query')->andReturn(true);
    $query->expects('get')->with('post_type')->andReturn('shop_order');
    $query->expects('get')->with('meta_query')->andReturn(null);
    $query->expects('set')->with('meta_query', [
        ['key' => FiscalStatusMeta::META_STATUS, 'value' => 'failed', 'compare' => '='],
    ]);

    (new OrdersListFilter())->filterLegacyQuery($query);
});
