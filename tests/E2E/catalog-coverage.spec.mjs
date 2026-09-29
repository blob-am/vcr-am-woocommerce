import { test, expect } from '@playwright/test';
import { getMockLog, resetMockLog, resetMockPlan, setMockPlan } from './helpers/mock-vcr.mjs';
import { evalFile, wpCliAllowFailure } from './helpers/wp-cli.mjs';

/**
 * E2E: `wp vcr check-catalog` against real WooCommerce.
 *
 * The unit tests mock `wc_get_products()`, so they prove the diff logic and
 * nothing about the query behind it. What can only be checked here is whether
 * WooCommerce agrees: that a draft product is not orderable, that an external
 * product is never an order line, and that a variable product is read as its
 * variations — including a variation with no SKU of its own, which reports its
 * parent's and is the shape most likely to be got wrong.
 *
 * The register's catalogue is planned, not real, so the last test can hand the
 * plugin more offers than the API will list at once and watch it refuse to call
 * a SKU missing on the strength of a truncated list.
 */
function offer(externalId, archivedAt = null) {
    return {
        id: Math.abs(idFor(externalId)),
        externalId,
        type: 'product',
        classifierCode: '47.91',
        defaultMeasureUnit: 'pc',
        defaultDepartment: { internalId: 1 },
        title: [],
        archivedAt,
        createdAt: '2026-09-01T00:00:00Z',
    };
}

/** Stable per-SKU id, so a planned catalogue has no duplicate offer ids. */
function idFor(value) {
    let out = 0;
    for (const char of value) {
        out = (out * 31 + char.codePointAt(0)) | 0;
    }

    return out || 1;
}

/** Rows keyed by SKU, so a test can ask about one SKU without ordering games. */
async function coverageRows() {
    const { code, stdout } = await wpCliAllowFailure(['vcr', 'check-catalog', '--format=json']);
    const rows = stdout === '' ? [] : JSON.parse(stdout);

    return { code, rows, bySku: new Map(rows.map((row) => [row.sku, row])) };
}

test.describe('catalog coverage', () => {
    let products;

    test.beforeAll(async () => {
        products = JSON.parse(await evalFile('create-coverage-products.php'));
        expect(products.variations).toHaveLength(2);
    });

    test.beforeEach(async () => {
        await resetMockLog();
        await resetMockPlan();
    });

    test('reads variations, and skips what cannot be ordered', async () => {
        await setMockPlan('listOffers', {
            status: 200,
            body: [offer('E2E-COVERED'), offer('E2E-VAR-A')],
        });

        const { bySku } = await coverageRows();

        // Planned as offers, so there is nothing to report.
        expect(bySku.has('E2E-COVERED')).toBe(false);
        expect(bySku.has('E2E-VAR-A')).toBe(false);

        // No offer planned: the finding the command exists for.
        expect(bySku.get('E2E-MISSING')).toMatchObject({ problem: 'no-offer' });

        // A draft cannot be bought and an external product is a link off-site;
        // reporting either would be noise the merchant cannot act on.
        expect(bySku.has('E2E-DRAFT')).toBe(false);
        expect(bySku.has('E2E-EXTERNAL')).toBe(false);

        // The variation with no SKU of its own reports the parent's SKU — and
        // it is the variation that gets named, which is how we know the parent
        // was not read as an order line in its own right.
        const inherited = bySku.get('E2E-PARENT');
        expect(inherited).toMatchObject({ problem: 'no-offer' });
        expect(inherited.product).toContain('Large');
    });

    test('names the product that carries no SKU at all', async () => {
        await setMockPlan('listOffers', { status: 200, body: [] });

        const { rows } = await coverageRows();
        const noSku = rows.filter((row) => row.problem === 'no-sku');

        expect(noSku.map((row) => row.product)).toContain('No SKU product');
        expect(noSku.every((row) => row.sku === '')).toBe(true);
    });

    test('tells an archived offer from a missing one', async () => {
        await setMockPlan('listOffers', {
            status: 200,
            body: [offer('E2E-MISSING', '2026-09-01T00:00:00Z')],
        });

        const { bySku } = await coverageRows();

        expect(bySku.get('E2E-MISSING')).toMatchObject({ problem: 'archived-offer' });
    });

    test('exits non-zero so a scheduled run is heard', async () => {
        await setMockPlan('listOffers', { status: 200, body: [] });

        const { code } = await coverageRows();

        expect(code).toBe(1);
    });

    test('will not call a SKU missing on the strength of a truncated list', async () => {
        // 500 offers nothing in this store references, plus the one that
        // matters pushed past the cap: the bulk list cannot show it, so the
        // only route to a correct answer is asking for it by id.
        const filler = Array.from({ length: 500 }, (_, index) => offer(`E2E-FILLER-${index}`));
        await setMockPlan('listOffers', {
            status: 200,
            body: [...filler, offer('E2E-COVERED')],
        });

        const { bySku } = await coverageRows();

        const log = await getMockLog();
        const lookups = log.filter(
            (entry) => entry.method === 'GET' && entry.url.includes('externalId=E2E-COVERED'),
        );

        expect(lookups.length).toBeGreaterThan(0);
        expect(bySku.has('E2E-COVERED')).toBe(false);
    });
});
