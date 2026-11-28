/**
 * Turn the WC_VERSION the E2E matrix asks for into what `wp plugin install
 * woocommerce` takes.
 *
 *   "latest"  -> null: the plain install, which is the newest stable release.
 *   "next"    -> the newest release WordPress.org lists, pre-releases
 *                included. Between cycles that is the stable release itself,
 *                and the row repeats the "latest" one; during a beta or RC
 *                it is the build every store gets in a few weeks.
 *   "9.4.5"   -> itself.
 */

const PLUGIN_INFO_URL = 'https://api.wordpress.org/plugins/info/1.0/woocommerce.json';

// WooCommerce tags pre-releases as 9.4.0-beta.1 and 9.4.0-rc.2.
const VERSION_PATTERN = /^(\d+)\.(\d+)\.(\d+)(?:-(beta|rc)\.(\d+))?$/;
const STAGE_RANK = { beta: 0, rc: 1, stable: 2 };

/** @returns {number[] | null} a sortable key, or null for a tag that is not a release. */
function versionKey(version) {
    const match = VERSION_PATTERN.exec(version);

    if (match === null) {
        return null;
    }

    const [, major, minor, patch, stage = 'stable', number = '0'] = match;

    return [Number(major), Number(minor), Number(patch), STAGE_RANK[stage], Number(number)];
}

function compareKeys(left, right) {
    for (let index = 0; index < left.length; index++) {
        if (left[index] !== right[index]) {
            return left[index] - right[index];
        }
    }

    return 0;
}

async function newestListedRelease() {
    const response = await fetch(PLUGIN_INFO_URL);

    if (! response.ok) {
        throw new Error(`${PLUGIN_INFO_URL} answered ${response.status}`);
    }

    const info = await response.json();

    if (typeof info.versions !== 'object' || info.versions === null) {
        throw new Error(`${PLUGIN_INFO_URL} returned no "versions" map`);
    }

    const releases = Object.keys(info.versions)
        .map((version) => ({ version, key: versionKey(version) }))
        .filter((release) => release.key !== null)
        .sort((left, right) => compareKeys(left.key, right.key));

    const newest = releases.at(-1);

    if (newest === undefined) {
        throw new Error(`${PLUGIN_INFO_URL} lists no WooCommerce release`);
    }

    return newest.version;
}

/** @returns {Promise<string | null>} the version to pass as --version, or null for the plain install. */
export async function resolveWooCommerceVersion(requested) {
    if (requested === 'latest') {
        return null;
    }

    if (requested === 'next') {
        return newestListedRelease();
    }

    if (versionKey(requested) === null) {
        throw new Error(`WC_VERSION must be "latest", "next" or a release such as 9.4.5, got "${requested}"`);
    }

    return requested;
}
