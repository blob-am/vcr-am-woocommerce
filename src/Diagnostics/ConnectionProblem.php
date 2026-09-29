<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Why the plugin cannot talk to a VCR register right now.
 *
 * Every case is a different thing for the merchant to do, which is the
 * whole reason the enum exists: until 0.1.7 the settings screen collapsed
 * all of them — plus "the register is fine but has no cashiers" — into one
 * empty dropdown labelled "check your API key permissions". That sentence
 * was wrong for every cause except one, and it sent the merchant looking
 * at the key when the key was never the problem.
 *
 * The wording lives in {@see \BlobSolutions\WooCommerceVcrAm\Admin\ReadinessPanel}:
 * one place diagnoses, so no two screens can disagree about the cause.
 */
enum ConnectionProblem
{
    /** Nothing saved yet. Not a failure — the starting state. */
    case NoApiKey;

    /**
     * Nothing answered: DNS, TCP, TLS or a timeout. The merchant's host
     * blocks outbound HTTPS, or has no HTTP transport at all.
     */
    case Unreachable;

    /** HTTP 401 — VCR does not recognise the key at all. */
    case KeyRejected;

    /**
     * HTTP 403 while the register exists but has never been activated with
     * the SRC. VCR words this one "VCR is not initialized", and no key can
     * do anything about it: the register has to finish activation first.
     */
    case RegisterNotActivated;

    /** HTTP 403 for any other reason — the key may not use this register. */
    case AccessDenied;

    /** HTTP 5xx. Ours to fix, and the request id is the way in. */
    case ServerError;

    /** A 4xx we have no story for, or a response we could not parse. */
    case Unexpected;
}
