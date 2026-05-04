/**
 * Thin wrapper around `wp-env run cli wp ...` so test specs can drive
 * WP/WC state from Node without needing browser navigation for setup.
 *
 * Browser navigation is reserved for what we actually want to verify
 * (admin UI behaviour) — everything else (creating products, paying
 * orders, dispatching the queue) goes through this much faster path.
 */

import { spawn } from 'node:child_process';

/**
 * Run a single `wp ...` command inside the wp-env container.
 *
 * @param {string[]} args - Args after `wp` (e.g. `['option', 'get', 'siteurl']`)
 * @param {{ json?: boolean }} [opts]
 * @returns {Promise<string>} stdout (trimmed)
 */
export async function wpCli(args, opts = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn('npx', ['wp-env', 'run', 'cli', 'wp', ...args], {
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
