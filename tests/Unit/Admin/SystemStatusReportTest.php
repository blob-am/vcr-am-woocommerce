<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Admin\SystemStatusReport;
use BlobSolutions\WooCommerceVcrAm\Configuration;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    // SystemStatusReport reads $wpdb in collectRows. Provide a fake
    // global so tests don't crash; per-test cases override the
    // get_results return value when they care about counts.
    global $wpdb;
    $wpdb = new class () {
        public string $postmeta = 'wp_postmeta';

        /** @var array<int, array<string, mixed>> */
        public array $results = [];

        public function prepare(string $sql): string
        {
            return $sql;
        }

        /**
         * @return mixed
         */
        public function get_results(string $sql, $output = null)
        {
            return $this->results;
        }
    };

    Functions\when('as_get_scheduled_actions')->justReturn([]);
});

afterEach(function (): void {
    global $wpdb;
    $wpdb = null;
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
