<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Support\ReportSender;
use BlobSolutions\WooCommerceVcrAm\Support\SendHandler;
use BlobSolutions\WooCommerceVcrAm\Support\SendOutcome;
use BlobSolutions\WooCommerceVcrAm\Support\SendProblem;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    $_POST = [];
    Functions\when('admin_url')->alias(fn (string $path = '') => 'https://shop.example/wp-admin/' . $path);
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('add_query_arg')->alias(
        fn (array $args, string $url) => $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args),
    );
    Functions\when('sanitize_textarea_field')->returnArg();
    Functions\when('wp_die')->alias(function (string $message, string $title = '', array $args = []): never {
        throw new RuntimeException('wp_die:' . ($args['response'] ?? 0));
    });
    Functions\when('wp_safe_redirect')->alias(static function (string $url): never {
        throw new RuntimeException('redirect:' . $url);
    });
    Functions\when('current_user_can')->justReturn(true);

    $this->sender = Mockery::mock(ReportSender::class);
    $this->handler = new SendHandler($this->sender);
});

afterEach(function (): void {
    $_POST = [];
});

it('hooks the admin-post action', function (): void {
    Actions\expectAdded('admin_post_' . SendHandler::ACTION)->once();

    $this->handler->register();
});

it('checks the nonce bound to the order before anything else', function (): void {
    $_POST = ['order_id' => '8402', 'vcr_report_message' => 'x'];
    Functions\expect('check_admin_referer')->once()->with('vcr-send-report_8402')
        ->andThrow(new RuntimeException('bad nonce'));
    $this->sender->expects('send')->never();

    expect(fn () => $this->handler->handle())->toThrow(RuntimeException::class, 'bad nonce');
});

it('refuses a user who may not manage WooCommerce', function (): void {
    Functions\when('check_admin_referer')->justReturn(1);
    Functions\when('current_user_can')->justReturn(false);
    $this->sender->expects('send')->never();

    expect(fn () => $this->handler->handle())->toThrow(RuntimeException::class, 'wp_die:403');
});

it('sends the store report and comes back with the reference', function (): void {
    Functions\when('check_admin_referer')->justReturn(1);
    $_POST = ['vcr_report_message' => " Paid, no receipt \n", 'vcr_report_contact' => 'owner@shop.example'];
    $this->sender->expects('send')->with(null, 'Paid, no receipt', 'owner@shop.example')
        ->andReturn(SendOutcome::sent('7K4M-2QXD'));

    expect(fn () => $this->handler->handle())->toThrow(
        RuntimeException::class,
        'redirect:https://shop.example/wp-admin/admin.php?page=vcr-problem-report&vcr_report_sent=7K4M-2QXD',
    );
});

it('sends the report of the order the form was for', function (): void {
    Functions\when('check_admin_referer')->justReturn(1);
    $_POST = ['order_id' => '8402', 'vcr_report_message' => 'x'];
    $order = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 8402 ? $order : false);
    $this->sender->expects('send')->with($order, 'x', '')->andReturn(SendOutcome::sent('AAAA-BBBB'));

    expect(fn () => $this->handler->handle())
        ->toThrow(RuntimeException::class, 'order_id=8402&vcr_report_sent=AAAA-BBBB');
});

it('does not send the store report in place of an order that is gone', function (): void {
    Functions\when('check_admin_referer')->justReturn(1);
    $_POST = ['order_id' => '999', 'vcr_report_message' => 'x'];
    Functions\when('wc_get_order')->justReturn(false);
    $this->sender->expects('send')->never();

    expect(fn () => $this->handler->handle())
        ->toThrow(RuntimeException::class, 'vcr_report_problem=report_unavailable');
});

it('comes back with the reason when the report was not sent', function (): void {
    Functions\when('check_admin_referer')->justReturn(1);
    $_POST = ['vcr_report_message' => 'x'];
    $this->sender->allows('send')->andReturn(SendOutcome::failed(SendProblem::Unreachable, 'timeout'));

    expect(fn () => $this->handler->handle())
        ->toThrow(RuntimeException::class, 'vcr_report_problem=unreachable');
});
