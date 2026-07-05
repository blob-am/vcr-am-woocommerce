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
 * Pristine baseline response plan. `responsePlan` (below) is cloned
 * from this; individual entries can be overridden via POST to
 * `/__test/plan` (`{ "endpoint": "registerSale", "status": 503 }`), and
 * POST to `/__test/plan/reset` restores this exact baseline. DEFAULT_PLAN
 * itself is never mutated, so a reset always recovers a clean slate —
 * without it, one test's 5xx override would leak into every later spec.
 */
const DEFAULT_PLAN = {
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
        timestamp: new Date().toISOString(),
    });

    if (req.url === '/__test/log' && req.method === 'GET') {
        return jsonResponse(res, 200, requestLog);
    }

    if (req.url === '/__test/log/reset' && req.method === 'POST') {
        requestLog.length = 0;
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

    if (req.url.startsWith('/api/v1/cashiers') && req.method === 'GET') {
        const plan = responsePlan.listCashiers;
        return jsonResponse(res, plan.status, plan.body);
    }

    if (req.url === '/api/v1/sales' && req.method === 'POST') {
        const plan = responsePlan.registerSale;
        return jsonResponse(res, plan.status, plan.body);
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
