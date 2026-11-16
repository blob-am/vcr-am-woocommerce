<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Cli\ReportCommand;
use BlobSolutions\WooCommerceVcrAm\Support\ReportBuilder;
use BlobSolutions\WooCommerceVcrAm\Support\ReportUnavailable;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    $this->builder = Mockery::mock(ReportBuilder::class);
    $this->command = new ReportCommand($this->builder);
});

function runReportCommand(ReportCommand $command, array $assoc): string
{
    ob_start();

    try {
        $command->report([], $assoc);
    } finally {
        $output = (string) ob_get_clean();
    }

    return $output;
}

it('prints the store report', function (): void {
    $this->builder->expects('json')->with(null)->andReturn('{"format": 1}');

    expect(runReportCommand($this->command, []))->toBe("{\"format\": 1}\n");
});

it('prints the report for one order', function (): void {
    $order = Mockery::mock(WC_Order::class);
    Functions\when('wc_get_order')->alias(fn (int $id) => $id === 8402 ? $order : false);
    $this->builder->expects('json')->with($order)->andReturn('{}');

    expect(runReportCommand($this->command, ['order' => '8402']))->toBe("{}\n");
});

it('refuses an order id that is not a number, without building anything', function (): void {
    $this->builder->expects('json')->never();

    expect(fn () => runReportCommand($this->command, ['order' => 'abc']))
        ->toThrow(RuntimeException::class, '--order must be an order id');
});

it('says so when the order does not exist, rather than reporting on the store', function (): void {
    Functions\when('wc_get_order')->justReturn(false);
    $this->builder->expects('json')->never();

    expect(fn () => runReportCommand($this->command, ['order' => '999']))
        ->toThrow(RuntimeException::class, 'Order 999 not found');
});

it('fails with the reason when the report cannot be built', function (): void {
    $this->builder->allows('json')->andThrow(new ReportUnavailable('The plugin\'s journal could not be read: gone'));

    expect(fn () => runReportCommand($this->command, []))
        ->toThrow(RuntimeException::class, 'journal could not be read: gone');
});
