<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Diagnostics;

use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrApiException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrNetworkException;
use BlobSolutions\WooCommerceVcrAm\Vendor\BlobSolutions\VcrAm\Exception\VcrValidationException;
use Throwable;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Turns whatever the SDK threw into one {@see ConnectionFailure}.
 *
 * The one place that reads the vendor's exception hierarchy for diagnosis,
 * so the catalog fetches and the connection probe cannot reach different
 * conclusions about the same error.
 *
 * Status codes are the SDK's own: `VcrApiException::$statusCode` is the raw
 * HTTP status, and `$apiErrorMessage` the API envelope's `error` string.
 * The one string match in here — "not initialized" — is VCR's wording for
 * a register that has never completed SRC activation, and it is the
 * difference between "your key is wrong" (it is not) and "this register
 * cannot sell yet". Should the wording ever change, this degrades to
 * AccessDenied rather than to a wrong answer.
 */
final class ProblemClassifier
{
    private const NOT_INITIALIZED_MARKER = 'not initialized';

    private const HTTP_UNAUTHORIZED = 401;

    private const HTTP_FORBIDDEN = 403;

    private const HTTP_SERVER_ERROR_FLOOR = 500;

    public function classify(Throwable $error): ConnectionFailure
    {
        if ($error instanceof VcrNetworkException) {
            return new ConnectionFailure(
                ConnectionProblem::Unreachable,
                technicalDetail: $error->getMessage(),
            );
        }

        if ($error instanceof VcrApiException) {
            return $this->classifyApiError($error);
        }

        if ($error instanceof VcrValidationException) {
            return new ConnectionFailure(
                ConnectionProblem::Unexpected,
                technicalDetail: $error->getMessage(),
            );
        }

        return new ConnectionFailure(
            ConnectionProblem::Unexpected,
            technicalDetail: $error->getMessage(),
        );
    }

    private function classifyApiError(VcrApiException $error): ConnectionFailure
    {
        $detail = $error->apiErrorMessage ?? $error->getMessage();

        if ($error->statusCode === self::HTTP_UNAUTHORIZED) {
            return new ConnectionFailure(ConnectionProblem::KeyRejected, technicalDetail: $detail);
        }

        if ($error->statusCode === self::HTTP_FORBIDDEN) {
            $problem = $this->mentionsUninitializedRegister($error->apiErrorMessage)
                ? ConnectionProblem::RegisterNotActivated
                : ConnectionProblem::AccessDenied;

            return new ConnectionFailure($problem, technicalDetail: $detail);
        }

        if ($error->statusCode >= self::HTTP_SERVER_ERROR_FLOOR) {
            return new ConnectionFailure(
                ConnectionProblem::ServerError,
                requestId: $error->requestId,
                technicalDetail: $detail,
            );
        }

        return new ConnectionFailure(ConnectionProblem::Unexpected, technicalDetail: $detail);
    }

    private function mentionsUninitializedRegister(?string $apiErrorMessage): bool
    {
        if ($apiErrorMessage === null) {
            return false;
        }

        return stripos($apiErrorMessage, self::NOT_INITIALIZED_MARKER) !== false;
    }
}
