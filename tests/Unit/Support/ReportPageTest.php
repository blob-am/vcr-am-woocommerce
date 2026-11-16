<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Journal\StoreException;
use BlobSolutions\WooCommerceVcrAm\Support\ReportBuilder;
use BlobSolutions\WooCommerceVcrAm\Support\ReportPage;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    unset($_GET['order_id']);
    Functions\when('admin_url')->alias(fn (string $path = '') => 'https://shop.example/wp-admin/' . $path);
    Functions\when('add_query_arg')->alias(
        fn (array $args, string $url) => $url . '?' . http_build_query($args),
    );
    Functions\when('wp_nonce_url')->alias(fn (string $url, string $action) => $url . '&_wpnonce=' . $action);
    Functions\when('wp_json_encode')->alias(fn (mixed $value, int $flags = 0) => json_encode($value, $flags));
    Functions\when('wp_die')->alias(function (string $message, string $title = '', array $args = []): never {
        throw new RuntimeException('wp_die:' . ($args['response'] ?? 0));
    });

    $this->builder = Mockery::mock(ReportBuilder::class);
    $this->page = new ReportPage($this->builder, '/plugins/vcr/vcr.php', '0.2.0');
});

afterEach(function (): void {
    unset($_GET['order_id']);
});

function renderReportPage(ReportPage $page): string
{
    ob_start();

    try {
        $page->render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}

it('hooks the menu, the script and the download', function (): void {
    Actions\expectAdded('admin_menu')->once();
    Actions\expectAdded('admin_head')->once();
    Actions\expectAdded('admin_enqueue_scripts')->once();
    Actions\expectAdded('admin_post_' . ReportPage::DOWNLOAD_ACTION)->once();

    $this->page->register();
});

it('keeps its menu entry while WordPress checks access, and removes it only afterwards', function (): void {
    Functions\expect('add_submenu_page')->once();
    Functions\expect('remove_submenu_page')->never();

    $this->page->addPage();
});

it('takes itself out of the menu once the screen is being drawn', function (): void {
    Functions\expect('remove_submenu_page')->once()->with('woocommerce', 'vcr-problem-report');

    $this->page->hideFromMenu();
});

it('links to itself, with the order when there is one', function (): void {
    expect(ReportPage::url())->toBe('https://shop.example/wp-admin/admin.php?page=vcr-problem-report')
        ->and(ReportPage::url(8402))
        ->toBe('https://shop.example/wp-admin/admin.php?page=vcr-problem-report&order_id=8402');
});

it('refuses to render without the capability', function (): void {
    Functions\when('current_user_can')->justReturn(false);

    expect(fn () => renderReportPage($this->page))->toThrow(RuntimeException::class, 'wp_die:403');
});

it('shows the store report, says nothing is sent, and offers copy and download', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $this->builder->expects('build')->with(null)->andReturn(['plugin' => ['version' => '0.2.0'], 'note' => 'Հայերեն']);

    $html = renderReportPage($this->page);

    expect($html)
        ->toContain('&quot;version&quot;: &quot;0.2.0&quot;')
        ->toContain('Հայերեն')
        ->toContain('Nothing is sent anywhere from this page')
        ->toContain('id="vcr-report-copy"')
        ->toContain('action=vcr_download_report')
        ->toContain('_wpnonce=vcr-download-report_0');
});

it('builds the report for the order named in the address', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $_GET['order_id'] = '8402';
    $order = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 8402 ? $order : false);
    $this->builder->expects('build')->with($order)->andReturn(['order' => ['id' => 8402]]);

    $html = renderReportPage($this->page);

    expect($html)
        ->toContain('everything the plugin knows about this order')
        ->toContain('order_id=8402')
        ->toContain('_wpnonce=vcr-download-report_8402');
});

it('falls back to the store report and says so when the order does not exist', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $_GET['order_id'] = '999';
    Functions\when('wc_get_order')->justReturn(false);
    $this->builder->expects('build')->with(null)->andReturn(['plugin' => []]);

    $html = renderReportPage($this->page);

    expect($html)->toContain('This order does not exist')->toContain('_wpnonce=vcr-download-report_0');
});

it('says why there is no report when the journal cannot be read', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $this->builder->allows('build')->andThrow(new StoreException('Journal read failed: table is missing'));

    $html = renderReportPage($this->page);

    expect($html)
        ->toContain('journal could not be read: Journal read failed: table is missing')
        ->not->toContain('vcr-report-text');
});

it('refuses a download without the capability', function (): void {
    Functions\expect('check_admin_referer')->once()->with('vcr-download-report_0');
    Functions\when('current_user_can')->justReturn(false);

    expect(fn () => $this->page->download())->toThrow(RuntimeException::class, 'wp_die:403');
});

it('loads its script only on its own screen', function (): void {
    Functions\when('plugins_url')->justReturn('https://shop.example/x/report.js');
    Functions\expect('wp_enqueue_script')->once();
    Functions\expect('wp_localize_script')->once();

    $this->page->enqueueScript('woocommerce_page_wc-settings');
    $this->page->enqueueScript(null);
    $this->page->enqueueScript('woocommerce_page_vcr-problem-report');
});
