<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Support\ReportBuilder;
use BlobSolutions\WooCommerceVcrAm\Support\ReportPage;
use BlobSolutions\WooCommerceVcrAm\Support\ReportUnavailable;
use BlobSolutions\WooCommerceVcrAm\Support\SendForm;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    $_GET = [];
    Functions\when('admin_url')->alias(fn (string $path = '') => 'https://shop.example/wp-admin/' . $path);
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('add_query_arg')->alias(
        fn (array $args, string $url) => $url . '?' . http_build_query($args),
    );
    Functions\when('wp_nonce_url')->alias(fn (string $url, string $action) => $url . '&_wpnonce=' . $action);
    Functions\when('wp_die')->alias(function (string $message, string $title = '', array $args = []): never {
        throw new RuntimeException('wp_die:' . ($args['response'] ?? 0));
    });

    $this->builder = Mockery::mock(ReportBuilder::class);
    $this->config = Mockery::mock(Configuration::class);
    $this->config->allows('hasCredentials')->andReturn(true)->byDefault();
    Functions\when('wp_nonce_field')->alias(function (string $action): void {
        echo '<input type="hidden" name="_wpnonce" value="' . $action . '" />';
    });
    Functions\when('wp_get_current_user')->justReturn((object) ['user_email' => 'owner@shop.example']);
    $this->page = new ReportPage($this->builder, new SendForm($this->config), '/plugins/vcr/vcr.php', '0.2.0');
});

afterEach(function (): void {
    $_GET = [];
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
    $this->builder->expects('json')->with(null)->andReturn("{\n    \"version\": \"0.2.0\",\n    \"note\": \"Հայերեն\"\n}");

    $html = renderReportPage($this->page);

    expect($html)
        ->toContain('&quot;version&quot;: &quot;0.2.0&quot;')
        ->toContain('Հայերեն')
        ->toContain('Nothing leaves this site unless you send it yourself')
        ->toContain('id="vcr-report-copy"')
        ->toContain('action=vcr_download_report')
        ->toContain('_wpnonce=vcr-download-report_0');
});

it('builds the report for the order named in the address', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $_GET['order_id'] = '8402';
    $order = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 8402 ? $order : false);
    $this->builder->expects('json')->with($order)->andReturn('{}');

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
    $this->builder->expects('json')->with(null)->andReturn('{}');

    $html = renderReportPage($this->page);

    expect($html)->toContain('This order does not exist')->toContain('_wpnonce=vcr-download-report_0');
});

it('says why there is no report when the journal cannot be read', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $this->builder->allows('json')->andThrow(new ReportUnavailable('The plugin\'s journal could not be read: table is missing'));

    $html = renderReportPage($this->page);

    expect($html)
        ->toContain('The report could not be built. The plugin&#039;s journal could not be read: table is missing')
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

it('offers to send, saying what goes where, bound to the order on screen', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $_GET['order_id'] = '8402';
    Functions\when('wc_get_order')->justReturn(Mockery::mock(WC_Order::class));
    $this->builder->allows('json')->andReturn('{}');

    $html = renderReportPage($this->page);

    expect($html)
        ->toContain('Nothing is sent until you press it')
        ->toContain('name="action" value="vcr_send_report"')
        ->toContain('name="order_id" value="8402"')
        ->toContain('value="vcr-send-report_8402"')
        ->toContain('name="vcr_report_message"')
        ->toContain('value="owner@shop.example"')
        ->toContain('id="vcr-report-send"');
});

it('offers no send button to a store with no register connected', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $this->config->allows('hasCredentials')->andReturn(false);
    $this->builder->allows('json')->andReturn('{}');

    $html = renderReportPage($this->page);

    expect($html)->toContain('not connected to a register yet')->not->toContain('vcr-report-send');
});

it('shows the reference after a report was sent', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $_GET[SendForm::SENT_PARAM] = '7K4M-2QXD';
    $this->builder->allows('json')->andReturn('{}');

    expect(renderReportPage($this->page))->toContain('Its reference is 7K4M-2QXD');
});

it('does not echo a reference that is not shaped like one', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $_GET[SendForm::SENT_PARAM] = 'call +374 91 000000 now';
    $this->builder->allows('json')->andReturn('{}');

    expect(renderReportPage($this->page))->not->toContain('+374')->not->toContain('was sent to VCR.AM support');
});

it('says nothing was sent, and why, after a failure', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    $_GET[SendForm::PROBLEM_PARAM] = 'too_many_reports';
    $this->builder->allows('json')->andReturn('{}');

    expect(renderReportPage($this->page))
        ->toContain('too many reports in the last hour')
        ->toContain('Nothing was sent.');
});
