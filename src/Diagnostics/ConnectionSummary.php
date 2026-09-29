<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * One plain-text line naming the register this store talks to, for the
 * places that report rather than instruct: the WooCommerce status report
 * and `wp vcr status`.
 *
 * It replaced a "Test mode: Disabled" row that read an option nothing ever
 * used, so the report answered a question about the plugin's own settings
 * instead of the one support actually asks — which register is this, and is
 * it a sandbox. The register knows; we just ask it.
 *
 * Short labels here, full guidance in
 * {@see \BlobSolutions\WooCommerceVcrAm\Admin\ReadinessPanel}: a support
 * report wants one scannable line, a merchant fixing their setup wants the
 * sentence that says what to do.
 */
final class ConnectionSummary
{
    public function line(ConnectionState $state): string
    {
        $identity = $state->identity();
        if ($identity !== null) {
            return sprintf(
                /* translators: 1: register id, 2: sandbox/production, 3: business name, 4: TIN. */
                __('#%1$d (%2$s) — %3$s, TIN %4$s', 'vcr-am-fiscal-receipts'),
                $identity->vcrId,
                $identity->isSandbox
                    ? __('sandbox', 'vcr-am-fiscal-receipts')
                    : __('production', 'vcr-am-fiscal-receipts'),
                $identity->entityName,
                $identity->tin,
            );
        }

        return $this->failureLine($state->failure());
    }

    /**
     * The same short labels for a fetch that came back with a reason
     * instead of a register -- the catalog coverage check has a
     * {@see \BlobSolutions\WooCommerceVcrAm\Diagnostics\ConnectionFailure}
     * in hand and no `ConnectionState` to wrap it in. One vocabulary, so
     * two surfaces cannot name the same problem differently.
     */
    public function failureLine(?ConnectionFailure $failure): string
    {
        $problem = $failure === null ? ConnectionProblem::Unexpected : $failure->problem;

        return match ($problem) {
            ConnectionProblem::NoApiKey => __('No API key saved', 'vcr-am-fiscal-receipts'),
            ConnectionProblem::Unreachable => __('Unknown — vcr.am unreachable from this server', 'vcr-am-fiscal-receipts'),
            ConnectionProblem::KeyRejected => __('Unknown — API key rejected', 'vcr-am-fiscal-receipts'),
            ConnectionProblem::RegisterNotActivated => __('Not activated with the tax service yet', 'vcr-am-fiscal-receipts'),
            ConnectionProblem::AccessDenied => __('Unknown — access to this register denied', 'vcr-am-fiscal-receipts'),
            ConnectionProblem::ServerError => __('Unknown — vcr.am returned an error', 'vcr-am-fiscal-receipts'),
            ConnectionProblem::Unexpected => __('Unknown — unreadable answer from vcr.am', 'vcr-am-fiscal-receipts'),
        };
    }
}
