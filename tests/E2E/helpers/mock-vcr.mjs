/**
 * HTTP client for the mock VCR server's test-control endpoints
 * (/__test/log, /__test/plan). Used by spec files to assert what the
 * plugin sent and to scenario-program the responses.
 */

const MOCK_BASE = process.env.MOCK_VCR_URL ?? 'http://127.0.0.1:9876';

export async function resetMockLog() {
    const res = await fetch(`${MOCK_BASE}/__test/log/reset`, { method: 'POST' });
    if (!res.ok) {
        throw new Error(`mock log reset failed: HTTP ${res.status}`);
    }
}

/**
 * Override the canned response for a single endpoint. Status defaults
 * to 200 if omitted; body to whatever the default plan has.
 *
 * @param {'listCashiers'|'registerSale'} endpoint
 * @param {{ status?: number, body?: unknown }} update
 */
export async function setMockPlan(endpoint, update) {
    const res = await fetch(`${MOCK_BASE}/__test/plan`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ endpoint, ...update }),
    });
    if (!res.ok) {
        throw new Error(`mock plan update failed: HTTP ${res.status}`);
    }
}

/**
 * @returns {Promise<Array<{ method: string, url: string, body: unknown, timestamp: string }>>}
 */
export async function getMockLog() {
    const res = await fetch(`${MOCK_BASE}/__test/log`);
    if (!res.ok) {
        throw new Error(`mock log fetch failed: HTTP ${res.status}`);
    }

    return res.json();
}
