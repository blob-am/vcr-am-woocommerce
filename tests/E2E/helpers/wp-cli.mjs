/**
 * Thin wrapper around `wp-env run cli wp ...` so test specs can drive
 * WP/WC state from Node without needing browser navigation for setup.
 *
 * Browser navigation is reserved for what we actually want to verify
 * (admin UI behaviour) — everything else (creating products, paying
 * orders, dispatching the queue) goes through this much faster path.
 */

import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

/**
 * Call wp-env's installed binary rather than going through `npx`, which adds
 * roughly a second to every invocation. The suite makes well over a hundred
 * of them, so it is the difference between a spec fitting its timeout and
 * not. Resolved against this file rather than the CWD so it holds wherever
 * Playwright is invoked from.
 */
const WP_ENV_BIN = fileURLToPath(new URL('../../../node_modules/.bin/wp-env', import.meta.url));

/**
 * Run a single `wp ...` command inside the wp-env container.
 *
 * @param {string[]} args - Args after `wp` (e.g. `['option', 'get', 'siteurl']`)
 * @param {{ json?: boolean }} [opts]
 * @returns {Promise<string>} stdout (trimmed)
 */
export async function wpCli(args, opts = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn(WP_ENV_BIN, ['run', 'cli', 'wp', ...args], {
            cwd: process.cwd(),
            env: process.env,
        });

        let stdout = '';
        let stderr = '';

        child.stdout.on('data', (chunk) => {
            stdout += chunk.toString('utf8');
        });
        child.stderr.on('data', (chunk) => {
            stderr += chunk.toString('utf8');
        });

        child.on('close', (code) => {
            if (code !== 0) {
                return reject(new Error(
                    `wp ${args.join(' ')} exited ${code}\nstderr:\n${stderr}\nstdout:\n${stdout}`,
                ));
            }
            resolve(stdout.trim());
        });
    });
}

/**
 * Convenience: parse a wp-cli `--format=json` output.
 */
export async function wpCliJson(args) {
    const out = await wpCli([...args, '--format=json']);

    return JSON.parse(out);
}

/**
 * Path, inside the container, of the E2E fixture scripts.
 *
 * `tests/` is excluded from the built ZIP (.distignore), and the ZIP is what
 * the suite now installs — so the fixtures cannot be addressed through the
 * plugin directory any more. `.wp-env.json` mounts them separately.
 */
export const SCRIPTS_PATH = 'wp-content/vcr-e2e/scripts';

/**
 * Run one of the fixture scripts in `tests/E2E/scripts/`.
 *
 * @param {string} script - File name, e.g. 'create-paid-order.php'
 * @param {string[]} [args] - Positional args, readable as `$args` in the script
 */
export async function evalFile(script, args = []) {
    return wpCli(['eval-file', `${SCRIPTS_PATH}/${script}`, ...args]);
}

/**
 * Read an order's (or refund's) `_vcr_*` meta as an object.
 *
 * Goes through WooCommerce's order CRUD rather than `wp post meta list`,
 * so it reads the right table whether the store runs HPOS or legacy post
 * storage. See read-order-meta.php.
 *
 * @param {string|number} orderId
 * @returns {Promise<Record<string, unknown>>}
 */
export async function readVcrMeta(orderId) {
    const out = await evalFile('read-order-meta.php', [String(orderId).trim()]);

    return JSON.parse(out);
}

/**
 * Clear `_vcr_*` meta from every order and refund, so a spec starts from a
 * known state. Action Scheduler rows are the caller's business — their
 * tables are unaffected by the order datastore.
 */
export async function resetFiscalMeta() {
    await evalFile('reset-fiscal-meta.php');
}
