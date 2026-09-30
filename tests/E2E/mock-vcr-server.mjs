/**
 * Tiny mock VCR API server for E2E tests.
 *
 * Listens on localhost:9876 and answers the small slice of the VCR.AM
 * API the WC plugin actually calls (`/api/v1/cashiers`,
 * `/api/v1/sales`). Plugged into wp-env via the bootstrap mu-plugin
 * which configures the plugin's base URL to point here at install time.
 *
 * Why a hand-rolled mock instead of WireMock or php-vcr recordings:
 *   - Zero install surface (just Node — already needed for Playwright).
 *   - Behaviour is programmable per test via in-memory `responsePlan`
 *     overrides, set through the `/__test/plan` admin endpoint. Lets
 *     a single test exercise success → 5xx → success retry sequences
 *     without restarting the server.
 *   - Real recordings would lock us to the wire format at recording
 *     time; the SDK schema validation already verifies wire correctness.
 *
 * Not exposed beyond localhost. Don't run in production.
 */

import http from 'node:http';

const PORT = Number(process.env.MOCK_VCR_PORT ?? 9876);
// Bind to 0.0.0.0 so the wp-env Docker container can reach us via
// host.docker.internal — which on macOS resolves to an IPv6 address
// that 127.0.0.1 alone doesn't answer on. The host firewall keeps
// non-localhost callers out in practice.
const HOST = process.env.MOCK_VCR_HOST ?? '0.0.0.0';

/**
 * Rows `GET /offers` returns at most, mirroring the server-side cap the
 * SDK documents. Kept in step with `Checker::OFFER_LIST_CAP` in the plugin.
 */
const OFFER_LIST_CAP = 500;

/**
 * Pristine baseline response plan. `responsePlan` (below) is cloned
 * from this; individual entries can be overridden via POST to
 * `/__test/plan` (`{ "endpoint": "registerSale", "status": 503 }`), and
 * POST to `/__test/plan/reset` restores this exact baseline. DEFAULT_PLAN
 * itself is never mutated, so a reset always recovers a clean slate —
 * without it, one test's 5xx override would leak into every later spec.
 */
const DEFAULT_PLAN = {
    // GET /whoami — who the key belongs to. The settings checklist and the
    // "Test connection" button both ask, and both report a sandbox register
    // as one, so the mock has to answer as a real register would.
    whoami: {
        status: 200,
        body: {
            vcrId: 90,
            crn: '99123456',
            mode: 'sandbox',
            tradingPlatformName: 'E2E Store',
            businessEntity: { tin: '01234567', name: 'E2E Merchant LLC' },
        },
    },
    listCashiers: {
        status: 200,
        body: [
            {
                deskId: 'A1',
                internalId: 1,
                name: { hy: { language: 'hy', content: 'Test cashier' } },
            },
        ],
    },
    // GET /offers — what the catalog coverage check reads. The real API
    // filters by `externalId` and caps the list server-side, so this mock
    // does both: the plugin only falls back to exact-match lookups when the
    // list came back capped, and that path is worth exercising for real.
    listOffers: {
        status: 200,
        body: [
            {
                id: 1,
                externalId: 'E2E-COVERED',
                type: 'product',
                classifierCode: '47.91',
                defaultMeasureUnit: 'pc',
                defaultDepartment: { internalId: 1 },
                title: [],
                archivedAt: null,
                createdAt: '2026-09-01T00:00:00Z',
            },
            {
                // The SKU the fiscal-flow fixture sells. It is here because the
                // plugin now asks whether the register has an offer for a
                // product before describing one, and a register that has it is
                // the case those tests are about -- adoption, not creation.
                id: 2,
                externalId: 'E2E-SKU-1',
                type: 'product',
                classifierCode: '47.91',
                defaultMeasureUnit: 'pc',
                defaultDepartment: { internalId: 1 },
                title: [],
                archivedAt: null,
                createdAt: '2026-09-01T00:00:00Z',
            },
        ],
    },
    // POST /connect/requests — step one of pairing. Unauthenticated, like the
    // real one: a store being paired has no key yet. Only `status` is read
    // from here on the success path; the body is built per request, because
    // the response has to name the request that was just registered.
    registerPairingRequest: {
        status: 201,
        body: null,
    },
    // POST /connect/exchange — step two. The key it hands back is what the
    // plugin must end up storing, so the spec asserts on this exact value.
    exchangePairingCode: {
        status: 200,
        body: {
            apiKey: 'paired-key-from-exchange',
            expiresAt: '2028-01-01T00:00:00.000Z',
            vcrId: 90,
            crn: '99123456',
            registerName: 'E2E Store',
        },
    },
    registerSale: {
        status: 200,
        body: {
            urlId: 'rcpt-test-1',
            saleId: 1,
            crn: 'CRN-TEST',
            srcReceiptId: 1,
            fiscal: 'FISCAL-TEST',
        },
    },
    getExchangeRate: {
        status: 200,
        body: {
            currency: 'USD',
            ratePerUnit: 363.38,
            amount: 1,
            rateDate: '2026-09-24',
            saleDate: '2026-09-25',
            ruleVersion: 'HO-234-N',
            source: 'CBA',
        },
    },
    registerSaleRefund: {
        status: 200,
        body: {
            urlId: 'rfd-test-1',
            saleRefundId: 1,
            crn: 'REF-CRN-TEST',
            receiptId: 1,
            fiscal: 'REF-FISCAL-TEST',
        },
    },
};

/**
 * Live plan the request handler reads. Mutated in place by `/__test/plan`
 * overrides; reassigned to a fresh deep clone of DEFAULT_PLAN by
 * `/__test/plan/reset`.
 */
let responsePlan = structuredClone(DEFAULT_PLAN);

/** Audit log of inbound requests — exposed via `/__test/log` for assertions. */
const requestLog = [];

/**
 * Pairing requests registered this run, keyed by the id handed back. The
 * real server keeps a row per request; `/__test/approve` needs the same
 * lookup, because the merchant's browser carries only the id.
 */
const pairingRequests = new Map();

function jsonResponse(res, status, body) {
    res.statusCode = status;
    res.setHeader('Content-Type', 'application/json');
    res.end(JSON.stringify(body));
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        req.on('data', (chunk) => chunks.push(chunk));
        req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
        // Without this a mid-stream socket error leaves the promise — and
        // the request handler awaiting it — hanging forever.
        req.on('error', reject);
    });
}

async function handleRequest(req, res) {
    const body = await readBody(req);

    requestLog.push({
        method: req.method,
        url: req.url,
        body: body.length > 0 ? safeJsonParse(body) : null,
        // This one header, not the whole set: the log is served over HTTP at
        // /__test/log, and the request also carries X-API-Key. The plugin's
        // protection against duplicate receipts is "every attempt sends the
        // same Idempotency-Key", and that is only assertable from here.
        idempotencyKey: req.headers['idempotency-key'] ?? null,
        // Also not a secret, and the only place the plugin's own product token
        // can be observed arriving: what vcr.am's request log will show for a
        // store running this build.
        userAgent: req.headers['user-agent'] ?? null,
        timestamp: new Date().toISOString(),
    });

    if (req.url === '/__test/log' && req.method === 'GET') {
        return jsonResponse(res, 200, requestLog);
    }

    if (req.url === '/__test/log/reset' && req.method === 'POST') {
        requestLog.length = 0;
        pairingRequests.clear();
        return jsonResponse(res, 200, { ok: true });
    }

    if (req.url === '/__test/plan/reset' && req.method === 'POST') {
        responsePlan = structuredClone(DEFAULT_PLAN);
        return jsonResponse(res, 200, { ok: true });
    }

    if (req.url === '/__test/plan' && req.method === 'POST') {
        const update = safeJsonParse(body);
        if (update && update.endpoint && responsePlan[update.endpoint]) {
            if (typeof update.status === 'number') {
                responsePlan[update.endpoint].status = update.status;
            }
            if (update.body !== undefined) {
                responsePlan[update.endpoint].body = update.body;
            }
            return jsonResponse(res, 200, { ok: true, plan: responsePlan[update.endpoint] });
        }
        return jsonResponse(res, 400, { error: 'invalid plan payload' });
    }

    if (req.url.startsWith('/api/v1/whoami') && req.method === 'GET') {
        const plan = responsePlan.whoami;
        return jsonResponse(res, plan.status, plan.body);
    }

    if (req.url.startsWith('/api/v1/cashiers') && req.method === 'GET') {
        const plan = responsePlan.listCashiers;
        return jsonResponse(res, plan.status, plan.body);
    }

    if (req.url.startsWith('/api/v1/offers') && req.method === 'GET') {
        const plan = responsePlan.listOffers;

        // Honour both halves of the real endpoint's behaviour, because the
        // plugin's answer depends on them: an exact-match filter, and a
        // server-side row cap on the unfiltered list. Planning more than
        // OFFER_LIST_CAP rows is how a spec reaches the truncated path — the
        // overflow rows are then only findable by asking for them by id,
        // exactly as on a register with a catalogue that large.
        const asked = new URL(req.url, 'http://mock').searchParams.get('externalId');

        if (!Array.isArray(plan.body)) {
            return jsonResponse(res, plan.status, plan.body);
        }

        const body = asked !== null
            ? plan.body.filter((offer) => offer.externalId === asked)
            : plan.body.slice(0, OFFER_LIST_CAP);

        return jsonResponse(res, plan.status, body);
    }

    if (req.url === '/api/v1/connect/requests' && req.method === 'POST') {
        const plan = responsePlan.registerPairingRequest;

        // A spec that forced an error status wants that error verbatim.
        if (plan.status >= 400) {
            return jsonResponse(res, plan.status, plan.body ?? { error: 'pairing refused' });
        }

        // Store what the caller registered, exactly as the real server does:
        // the browser then carries only an opaque id, and `/__test/approve`
        // has to look the redirect back up rather than be handed it. That is
        // what makes this able to catch a plugin sending a wrong redirectUri.
        const registered = safeJsonParse(body);
        const requestId = `req_e2e_${pairingRequests.size + 1}`;
        pairingRequests.set(requestId, registered);

        // Built from the Host header the plugin reached us by, not from a
        // hardcoded localhost. The plugin refuses a consent URL on a host it
        // was not configured to talk to — an open-redirect guard that only
        // means anything if the test exercises it, and here the configured
        // host is `host.docker.internal`, not `localhost`. Real vcr.am serves
        // both the API and the consent screen from one host, which is the
        // shape this reproduces.
        const host = req.headers.host ?? `localhost:${PORT}`;

        return jsonResponse(res, plan.status, {
            requestId,
            connectUrl: `http://${host}/__test/approve?request=${requestId}`,
            expiresAt: '2030-01-01T00:00:00.000Z',
        });
    }

    if (req.url === '/api/v1/connect/exchange' && req.method === 'POST') {
        const plan = responsePlan.exchangePairingCode;
        return jsonResponse(res, plan.status, plan.body);
    }

    // Stands in for the merchant approving on vcr.am. The real consent screen
    // needs a signed-in merchant, which an E2E run has no way to be, so this
    // approves unconditionally and redirects to the URI the store registered.
    if (req.url.startsWith('/__test/approve') && req.method === 'GET') {
        const asked = new URL(req.url, 'http://mock');
        const registered = pairingRequests.get(asked.searchParams.get('request'));

        if (registered === undefined) {
            return jsonResponse(res, 404, { error: 'unknown pairing request' });
        }

        // searchParams.set, not string concatenation: the plugin's callback is
        // a wp-admin URL that already carries ?page= and ?tab=, and losing
        // those would send the merchant to a different screen.
        const target = new URL(registered.redirectUri);
        target.searchParams.set('code', 'e2e-pairing-code');
        target.searchParams.set('state', registered.state);

        res.statusCode = 302;
        res.setHeader('Location', target.toString());
        return res.end();
    }

    if (req.url === '/api/v1/sales' && req.method === 'POST') {
        const plan = responsePlan.registerSale;
        return jsonResponse(res, plan.status, plan.body);
    }

    if (req.url.startsWith('/api/v1/exchange-rate') && req.method === 'GET') {
        const plan = responsePlan.getExchangeRate;

        // Echo back the currency that was asked for, so a spec can assert the
        // plugin requested the order's currency rather than a hardcoded one.
        const asked = new URL(req.url, 'http://mock').searchParams.get('currency');
        const body = asked ? { ...plan.body, currency: asked.toUpperCase() } : plan.body;

        return jsonResponse(res, plan.status, body);
    }

    if (req.url === '/api/v1/sales/refund' && req.method === 'POST') {
        const plan = responsePlan.registerSaleRefund;
        return jsonResponse(res, plan.status, plan.body);
    }

    jsonResponse(res, 404, { error: 'unknown endpoint', url: req.url });
}

const server = http.createServer((req, res) => {
    // Fire-and-forget with a terminal .catch: a rejected readBody (socket
    // error) becomes a logged 500 rather than an unhandledRejection crash.
    handleRequest(req, res).catch((error) => {
        console.error('[mock-vcr] request handler failed', error);
        if (res.headersSent) {
            res.end();
        } else {
            jsonResponse(res, 500, { error: 'mock handler failure' });
        }
    });
});

function safeJsonParse(text) {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}

server.listen(PORT, HOST, () => {
    console.log(`[mock-vcr] listening on http://${HOST}:${PORT}`);
});

// Graceful shutdown so wp-env teardown doesn't leave orphan processes.
for (const sig of ['SIGINT', 'SIGTERM']) {
    process.on(sig, () => {
        console.log(`[mock-vcr] received ${sig}, shutting down`);
        server.close(() => process.exit(0));
    });
}
