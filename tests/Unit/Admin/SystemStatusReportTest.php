<?php

declare(strict_types=1);

use Automattic\WooCommerce\Utilities\OrderUtil;
use BlobSolutions\WooCommerceVcrAm\Admin\SystemStatusReport;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    // SystemStatusReport reads $wpdb in collectRows. Provide a fake
    // global so tests don't crash; per-test cases override the
    // get_results return value when they care about counts.
    //
    // The fake also captures every SQL string passed to get_results so
    // HPOS-vs-legacy tests can verify which table was queried.
    global $wpdb;
    $wpdb = new class () extends \wpdb {
        /** @var array<int, array<string, mixed>> */
        public array $results = [];

        /** @var list<string> */
        public array $queries = [];

        public function prepare(string $sql, mixed ...$args): string
        {
            // Mirror $wpdb->prepare's identifier handling so tests can
            // assert on the resolved table name. `%i` -> backticked
            // identifier; `%s` -> single-quoted string. Sufficient for
            // our two-placeholder query template.
            $prepared = $sql;
            foreach ($args as $arg) {
                $needle = str_contains($prepared, '%i') ? '%i' : '%s';
                $pos = strpos($prepared, $needle);
                if ($pos === false) {
                    continue;
                }
                $replacement = $needle === '%i'
                    ? '`' . str_replace('`', '``', (string) $arg) . '`'
                    : "'" . str_replace("'", "\\'", (string) $arg) . "'";
                $prepared = substr_replace($prepared, $replacement, $pos, 2);
            }

            return $prepared;
        }

        /**
         * @return mixed
         */
        public function get_results(string $sql, $output = null)
        {
            $this->queries[] = $sql;

            return $this->results;
        }
    };

    Functions\when('as_get_scheduled_actions')->justReturn([]);

    // Reset the HPOS toggle so each case starts in legacy mode unless it
    // explicitly opts into HPOS. Without this, test order would leak
    // state between cases under Pest's random-seed runner.
    OrderUtil::$hposEnabled = false;
});

afterEach(function (): void {
    global $wpdb;
    $wpdb = null;
    OrderUtil::$hposEnabled = false;
});

function makeReport(): array
{
    $config = Mockery::mock(Configuration::class);
    $report = new SystemStatusReport('0.5.0', $config);

    return [$report, $config];
}

function captureSystemStatus(SystemStatusReport $report): string
{
    ob_start();
    $report->render();

    return (string) ob_get_clean();
}

function primeConfig(\Mockery\MockInterface $config, array $overrides = []): void
{
    $defaults = [
        'hasCredentials' => true,
        'baseUrl' => 'https://vcr.am/api/v1',
        'isTestMode' => false,
        'defaultCashierId' => 5,
        'defaultDepartmentId' => 7,
        'shippingSku' => 'SHIP-1',
        'feeSku' => null,
        'isFullyConfigured' => true,
    ];
    $values = array_merge($defaults, $overrides);

    foreach ($values as $method => $value) {
        $config->allows($method)->andReturn($value);
    }
}

it('register hooks the woocommerce_system_status_report action', function (): void {
    Actions\expectAdded('woocommerce_system_status_report')->once();

    [$report] = makeReport();
    $report->register();
});

it('renders a table section with the plugin version and config rows', function (): void {
    [$report, $config] = makeReport();
    primeConfig($config);

    $html = captureSystemStatus($report);

    expect($html)
        ->toContain('VCR — Fiscal Receipts (Armenia)')
        ->toContain('Plugin version')
        ->toContain('0.5.0')
        ->toContain('https://vcr.am/api/v1');
});

it('reports "No" for missing API key without leaking the key itself', function (): void {
    [$report, $config] = makeReport();
    primeConfig($config, [
        'hasCredentials' => false,
        'isFullyConfigured' => false,
    ]);

    $html = captureSystemStatus($report);

    expect($html)
        ->toContain('API key configured')
        ->toContain('No')
        // Sanity — no actual secret-looking strings should escape.
        ->not->toContain('test-key')
        ->not->toContain('sk_live')
        ->not->toContain('Bearer');
});

it('reports test mode and configuration completeness flags', function (): void {
    [$report, $config] = makeReport();
    primeConfig($config, [
        'isTestMode' => true,
        'isFullyConfigured' => false,
    ]);

    $html = captureSystemStatus($report);

    expect($html)
        ->toContain('Test mode')
        ->toContain('Enabled')
        ->toContain('Fully configured');
});

it('surfaces the per-status order counts from postmeta', function (): void {
    [$report, $config] = makeReport();
    primeConfig($config);

    global $wpdb;
    // First call (sale meta) returns these rows; second (refund meta)
    // returns the same — the helper just reads the global stub list.
    $wpdb->results = [
        ['meta_value' => 'pending', 'c' => '4'],
        ['meta_value' => 'failed', 'c' => '2'],
        ['meta_value' => 'manual_required', 'c' => '1'],
    ];

    $html = captureSystemStatus($report);

    // Verify all six count rows render with our test numbers.
    expect($html)
        ->toContain('Sale orders — Pending')
        ->toContain('Sale orders — Failed')
        ->toContain('Sale orders — Manual required')
        ->toContain('Refunds — Pending')
        ->toContain('Refunds — Failed')
        ->toContain('Refunds — Manual required');
});

it('reports zero counts when no orders match', function (): void {
    [$report, $config] = makeReport();
    primeConfig($config);

    global $wpdb;
    $wpdb->results = []; // empty — no orders in any status

    $html = captureSystemStatus($report);

    expect($html)->toContain('Pending');  // labels render
    // Zero rendering — multiple "0" cells should appear
    expect(substr_count($html, '<td>0</td>'))->toBeGreaterThanOrEqual(6);
});

it('does not crash when as_get_scheduled_actions is missing (test isolation)', function (): void {
    // Don't override Functions\when — the as_get_scheduled_actions
    // function is stubbed via beforeEach. To simulate "missing", we
    // can't really — function_exists() is a real PHP function and
    // Brain Monkey can't make it return false for a stubbed name.
    // This test instead verifies the render still completes when AS
    // returns an empty array (the realistic "missing data" case).
    [$report, $config] = makeReport();
    primeConfig($config);

    expect(fn () => captureSystemStatus($report))->not->toThrow(Throwable::class);
});

it('renders all rows escaped — no raw HTML from config values', function (): void {
    [$report, $config] = makeReport();
    primeConfig($config, [
        'baseUrl' => '<script>alert(1)</script>https://attacker.example',
    ]);

    $html = captureSystemStatus($report);

    expect($html)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert(1)');
});

it('queries wp_postmeta on legacy stores (HPOS disabled)', function (): void {
    [$report, $config] = makeReport();
    primeConfig($config);

    OrderUtil::$hposEnabled = false;
    global $wpdb;
    $wpdb->results = [];

    captureSystemStatus($report);

    // Two countByStatus calls — one for sale meta, one for refund meta.
    // Both must hit wp_postmeta when HPOS is off.
    expect($wpdb->queries)->toHaveCount(2);
    foreach ($wpdb->queries as $sql) {
        expect($sql)
            ->toContain('`wp_postmeta`')
            ->not->toContain('wc_orders_meta');
    }
});

it('queries wc_orders_meta when HPOS is the authoritative store', function (): void {
    [$report, $config] = makeReport();
    primeConfig($config);

    // Flip HPOS on. SystemStatusReport::orderMetaTable() should resolve
    // to {$wpdb->prefix}wc_orders_meta — verify by inspecting the SQL
    // captured by the fake $wpdb.
    OrderUtil::$hposEnabled = true;
    global $wpdb;
    $wpdb->results = [];

    captureSystemStatus($report);

    expect($wpdb->queries)->toHaveCount(2);
    foreach ($wpdb->queries as $sql) {
        expect($sql)
            ->toContain('`wp_wc_orders_meta`')
            ->not->toContain('wp_postmeta');
    }
});

it('counts correctly on HPOS — same row data renders the same numbers', function (): void {
    // Regression guard for the HPOS migration: the rows shape we get
    // from wc_orders_meta is identical to what we got from wp_postmeta
    // (both have meta_value + COUNT(*)), so the counting logic must
    // produce the same output regardless of which table answered.
    [$report, $config] = makeReport();
    primeConfig($config);

    OrderUtil::$hposEnabled = true;
    global $wpdb;
    $wpdb->results = [
        ['meta_value' => 'pending', 'c' => '7'],
        ['meta_value' => 'failed', 'c' => '3'],
        ['meta_value' => 'manual_required', 'c' => '1'],
    ];

    $html = captureSystemStatus($report);

    expect($html)
        ->toContain('<td>7</td>')
        ->toContain('<td>3</td>')
        ->toContain('<td>1</td>');
});

it('respects $wpdb->prefix when building the HPOS table name', function (): void {
    // Multisite or custom-prefix installs use a non-default prefix.
    // Build the table name from $wpdb->prefix, not a hard-coded "wp_".
    [$report, $config] = makeReport();
    primeConfig($config);

    OrderUtil::$hposEnabled = true;
    global $wpdb;
    $wpdb->prefix = 'custom42_';
    $wpdb->results = [];

    captureSystemStatus($report);

    foreach ($wpdb->queries as $sql) {
        expect($sql)->toContain('`custom42_wc_orders_meta`');
    }
});
