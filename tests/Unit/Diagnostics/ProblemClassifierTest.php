<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionProblem;
use BlobSolutions\WooCommerceVcrAm\Diagnostics\ProblemClassifier;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrNetworkException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrValidationException;
use BlobSolutions\WooCommerceVcrAm\Vendor\Psr\Http\Message\RequestInterface;
use BlobSolutions\WooCommerceVcrAm\Vendor\Psr\Http\Message\ResponseInterface;

/**
 * One classifier, because the settings checklist, the cashier dropdown and
 * the status report all have to reach the same conclusion about the same
 * error. Each case below is a different sentence in front of the merchant
 * and a different thing for them to do; getting them confused is how 0.1.6
 * told people with a working key to go and check their key.
 */
function apiError(int $status, ?string $message, ?string $requestId = null): VcrApiException
{
    return new VcrApiException(
        statusCode: $status,
        apiErrorMessage: $message,
        rawBody: '{}',
        request: Mockery::mock(RequestInterface::class),
        response: Mockery::mock(ResponseInterface::class),
        requestId: $requestId,
    );
}

it('calls a transport failure unreachable, not a credentials problem', function (): void {
    $failure = (new ProblemClassifier())->classify(new VcrNetworkException(
        Mockery::mock(RequestInterface::class),
        new RuntimeException('cURL error 7: Failed to connect'),
    ));

    expect($failure->problem)->toBe(ConnectionProblem::Unreachable)
        ->and($failure->technicalDetail)->toContain('Failed to connect');
});

it('reads 401 as a rejected key', function (): void {
    expect((new ProblemClassifier())->classify(apiError(401, 'API key not valid'))->problem)
        ->toBe(ConnectionProblem::KeyRejected);
});

it('separates "register not activated" from "access denied" inside 403', function (): void {
    // VCR words the first one "VCR is not initialized". It is the one 403
    // that no key change can fix, and the one an integrator hits on a
    // register whose SRC activation was never finished.
    $classifier = new ProblemClassifier();

    expect($classifier->classify(apiError(403, 'VCR is not initialized'))->problem)
        ->toBe(ConnectionProblem::RegisterNotActivated)
        ->and($classifier->classify(apiError(403, 'Forbidden'))->problem)
        ->toBe(ConnectionProblem::AccessDenied);
});

it('matches the not-initialized wording case-insensitively but never guesses', function (): void {
    $classifier = new ProblemClassifier();

    expect($classifier->classify(apiError(403, 'VCR is NOT INITIALIZED'))->problem)
        ->toBe(ConnectionProblem::RegisterNotActivated)
        // No envelope at all (a proxy's HTML error page, say) degrades to
        // the generic 403 rather than to a story we cannot support.
        ->and($classifier->classify(apiError(403, null))->problem)
        ->toBe(ConnectionProblem::AccessDenied);
});

it('keeps the request id from a 5xx, because that is what support needs', function (): void {
    $failure = (new ProblemClassifier())->classify(
        apiError(503, 'Internal error', requestId: 'req_01HF'),
    );

    expect($failure->problem)->toBe(ConnectionProblem::ServerError)
        ->and($failure->requestId)->toBe('req_01HF');
});

it('admits it does not know, for a 4xx with no story and for anything else', function (): void {
    $classifier = new ProblemClassifier();

    $schemaMismatch = new VcrValidationException(
        rawBody: '{}',
        request: Mockery::mock(RequestInterface::class),
        response: Mockery::mock(ResponseInterface::class),
        detail: 'cashiers[0].deskId missing',
    );

    expect($classifier->classify(apiError(418, "I'm a teapot"))->problem)
        ->toBe(ConnectionProblem::Unexpected)
        ->and($classifier->classify($schemaMismatch)->problem)
        ->toBe(ConnectionProblem::Unexpected)
        ->and($classifier->classify(new RuntimeException('boom'))->problem)
        ->toBe(ConnectionProblem::Unexpected);
});
