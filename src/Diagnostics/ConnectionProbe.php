<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Diagnostics;

use BlobSolutions\WooCommerceVcrAm\Configuration;
use Throwable;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Asks the register who it is, and remembers the answer for a while.
 *
 * Every admin page that wants to say something true about the connection
 * goes through here, so the settings checklist, the cashier dropdown's
 * empty state and the system status report cannot contradict each other.
 *
 * Caching: a success is cached for an hour, because a register's identity
 * changes about never and the settings screen is re-rendered on every save
 * and every navigation. A failure is cached for a minute — long enough that
 * a page with three readers costs one request, short enough that a merchant
 * who just fixed the cause does not sit and wait for a stale verdict. Both
 * rows hold primitives only; see {@see RegisterIdentity} for why.
 *
 * Not declared `final` so unit tests can mock it — there's no production
 * extension point.
 */
class ConnectionProbe
{
    private const TRANSIENT_KEY = 'vcr_connection_state';

    private const SUCCESS_TTL_SECONDS = HOUR_IN_SECONDS;

    private const FAILURE_TTL_SECONDS = MINUTE_IN_SECONDS;

    /** In-request memo, so three readers on one page render cost one read. */
    private ?ConnectionState $memo = null;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly IdentityReaderFactory $readerFactory,
        private readonly ProblemClassifier $classifier = new ProblemClassifier(),
    ) {
    }

    public function state(): ConnectionState
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cached = $this->readCache();
        if ($cached !== null) {
            $this->memo = $cached;

            return $cached;
        }

        $state = $this->probe();
        $this->writeCache($state);
        $this->memo = $state;

        return $state;
    }

    public function refresh(): void
    {
        $this->memo = null;
        delete_transient(self::TRANSIENT_KEY);
    }

    private function probe(): ConnectionState
    {
        $apiKey = $this->configuration->apiKey();
        if ($apiKey === null) {
            return ConnectionState::noApiKey();
        }

        try {
            return ConnectionState::connected(
                $this->readerFactory->create($apiKey)->identify(),
            );
        } catch (Throwable $error) {
            return ConnectionState::failed($this->classifier->classify($error));
        }
    }

    private function readCache(): ?ConnectionState
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (! is_array($cached)) {
            return null;
        }

        $identity = isset($cached['identity']) && is_array($cached['identity'])
            ? RegisterIdentity::fromArray($cached['identity'])
            : null;
        if ($identity !== null) {
            return ConnectionState::connected($identity);
        }

        $problem = $cached['problem'] ?? null;
        if (! is_string($problem)) {
            return null;
        }

        foreach (ConnectionProblem::cases() as $case) {
            if ($case->name === $problem) {
                return ConnectionState::failed(new ConnectionFailure(
                    $case,
                    requestId: is_string($cached['requestId'] ?? null) ? $cached['requestId'] : null,
                    technicalDetail: is_string($cached['detail'] ?? null) ? $cached['detail'] : null,
                ));
            }
        }

        return null;
    }

    private function writeCache(ConnectionState $state): void
    {
        $identity = $state->identity();
        if ($identity !== null) {
            set_transient(
                self::TRANSIENT_KEY,
                ['identity' => $identity->toArray()],
                self::SUCCESS_TTL_SECONDS,
            );

            return;
        }

        $failure = $state->failure();
        if ($failure === null) {
            return;
        }

        // "No key saved yet" is not worth a cache row: it is answered by an
        // option read, and caching it would outlive the save that fixes it.
        if ($failure->problem === ConnectionProblem::NoApiKey) {
            return;
        }

        set_transient(
            self::TRANSIENT_KEY,
            [
                'problem' => $failure->problem->name,
                'requestId' => $failure->requestId,
                'detail' => $failure->technicalDetail,
            ],
            self::FAILURE_TTL_SECONDS,
        );
    }
}
