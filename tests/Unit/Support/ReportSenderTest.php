<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Configuration;
use BlobSolutions\WooCommerceVcrAm\Journal\Journal;
use BlobSolutions\WooCommerceVcrAm\Journal\StoreException;
use BlobSolutions\WooCommerceVcrAm\Support\ReportBuilder;
use BlobSolutions\WooCommerceVcrAm\Support\ReportSender;
use BlobSolutions\WooCommerceVcrAm\Support\ReportSubmitter;
use BlobSolutions\WooCommerceVcrAm\Support\ReportSubmitterFactory;
use BlobSolutions\WooCommerceVcrAm\Support\SendProblem;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrNetworkException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Input\SupportReportInput;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Model\SupportReportReceipt;
use BlobSolutions\WooCommerceVcrAm\Vendor\GuzzleHttp\Psr7\Request;
use BlobSolutions\WooCommerceVcrAm\Vendor\GuzzleHttp\Psr7\Response;

const TRACE_A = '640e22a83ff5df99b8bda3c661e781cd';
const TRACE_B = 'c2229b5f0eafaae52fb23bc6ee290f9e';

beforeEach(function (): void {
    $this->config = Mockery::mock(Configuration::class);
    $this->config->allows('apiKey')->andReturn('the-key')->byDefault();
    $this->builder = Mockery::mock(ReportBuilder::class);
    $this->builder->allows('build')->andReturn([
        'format' => 1,
        'journal' => [
            ['event' => 'api.call', 'traceId' => TRACE_A],
            ['event' => 'order.hook', 'traceId' => null],
            ['event' => 'api.call', 'traceId' => TRACE_B],
            ['event' => 'api.call', 'traceId' => TRACE_A],
            ['event' => 'api.call', 'traceId' => 'not-a-trace-id'],
        ],
    ])->byDefault();
    $this->submitter = Mockery::mock(ReportSubmitter::class);
    $this->factory = Mockery::mock(ReportSubmitterFactory::class);
    $this->factory->allows('create')->with('the-key')->andReturn($this->submitter)->byDefault();
    $this->journal = Mockery::spy(Journal::class);
    $this->sender = new ReportSender($this->config, $this->builder, $this->factory, $this->journal);
});

function apiRefusal(int $status, ?string $message): VcrApiException
{
    return new VcrApiException(
        statusCode: $status,
        apiErrorMessage: $message,
        rawBody: '',
        request: new Request('POST', 'https://vcr.am/api/v1/support/reports'),
        response: new Response($status),
    );
}

it('sends the report with the message, the contact and the trace ids of its calls', function (): void {
    $sent = null;
    $this->submitter->expects('submit')->andReturnUsing(function (SupportReportInput $input) use (&$sent) {
        $sent = $input;

        return new SupportReportReceipt('7K4M-2QXD', '2026-11-16T14:30:00Z');
    });

    $outcome = $this->sender->send(null, 'Paid at the bank, no receipt', ' owner@shop.example ');

    expect($outcome->reference)->toBe('7K4M-2QXD')
        ->and($outcome->problem)->toBeNull()
        ->and($sent->message)->toBe('Paid at the bank, no receipt')
        ->and($sent->contact)->toBe('owner@shop.example')
        ->and($sent->context['format'])->toBe(1)
        ->and($sent->traceIds)->toBe([TRACE_A, TRACE_B]);

    $this->journal->shouldHaveReceived('record')->once()->with('report.sent', null, ['reference' => '7K4M-2QXD']);
});

it('files the sending under the order the report is about', function (): void {
    $order = Mockery::mock(WC_Order::class);
    $order->allows('get_id')->andReturn(8402);
    $this->builder->expects('build')->with($order)->andReturn(['format' => 1, 'journal' => []]);
    $this->submitter->allows('submit')->andReturn(new SupportReportReceipt('AAAA-BBBB', '2026-11-16T14:30:00Z'));

    $this->sender->send($order, 'something', null);

    $this->journal->shouldHaveReceived('record')->with('report.sent', 8402, ['reference' => 'AAAA-BBBB']);
});

it('sends nothing without a description of the problem', function (): void {
    $this->factory->expects('create')->never();

    expect($this->sender->send(null, "  \n ", null)->problem)->toBe(SendProblem::MessageMissing);
});

it('sends nothing from a store that has no key', function (): void {
    $this->config->allows('apiKey')->andReturn(null);
    $this->factory->expects('create')->never();

    expect($this->sender->send(null, 'x', null)->problem)->toBe(SendProblem::NoApiKey);
});

it('sends nothing when the journal cannot be read, rather than a report without one', function (): void {
    $this->builder->allows('build')->andThrow(new StoreException('table is missing'));
    $this->factory->expects('create')->never();

    $outcome = $this->sender->send(null, 'x', null);

    expect($outcome->problem)->toBe(SendProblem::ReportUnavailable)
        ->and($outcome->detail)->toBe('table is missing');
});

it('names the reason the server refused', function (int $status, SendProblem $problem): void {
    $this->submitter->allows('submit')->andThrow(apiRefusal($status, 'server said no'));

    $outcome = $this->sender->send(null, 'x', null);

    expect($outcome->problem)->toBe($problem)
        ->and($outcome->detail)->toBe('server said no');

    $this->journal->shouldHaveReceived('record')->once()->with('report.send_failed', null, [
        'problem' => $problem->value,
        'error' => 'server said no',
    ]);
})->with([
    'rate limit' => [429, SendProblem::TooManyReports],
    'too large' => [413, SendProblem::TooLarge],
    'anything else' => [400, SendProblem::Rejected],
]);

it('reports an unreachable server as such', function (): void {
    $this->submitter->allows('submit')->andThrow(new VcrNetworkException(
        new Request('POST', 'https://vcr.am/api/v1/support/reports'),
        new RuntimeException('Connection timed out'),
    ));

    expect($this->sender->send(null, 'x', null)->problem)->toBe(SendProblem::Unreachable);
});

it('refuses a message the server would refuse, before sending it', function (): void {
    $this->submitter->expects('submit')->never();

    $outcome = $this->sender->send(null, str_repeat('a', 4001), null);

    expect($outcome->problem)->toBe(SendProblem::Rejected)
        ->and($outcome->detail)->toContain('at most 4000');
});
