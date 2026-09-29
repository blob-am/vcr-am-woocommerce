<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * The answer to "can this store talk to its register, and which one?".
 *
 * Exactly one of the two sides is set. Callers that only need "is it
 * working" ask {@see self::identity()} for null; callers that explain the
 * failure read {@see self::failure()}.
 */
final readonly class ConnectionState
{
    private function __construct(
        private ?RegisterIdentity $identity,
        private ?ConnectionFailure $failure,
    ) {
    }

    public static function connected(RegisterIdentity $identity): self
    {
        return new self($identity, null);
    }

    public static function failed(ConnectionFailure $failure): self
    {
        return new self(null, $failure);
    }

    public static function noApiKey(): self
    {
        return new self(null, new ConnectionFailure(ConnectionProblem::NoApiKey));
    }

    public function identity(): ?RegisterIdentity
    {
        return $this->identity;
    }

    public function failure(): ?ConnectionFailure
    {
        return $this->failure;
    }

    public function isConnected(): bool
    {
        return $this->identity !== null;
    }
}
