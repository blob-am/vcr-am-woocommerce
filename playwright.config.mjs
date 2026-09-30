import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for the WC plugin E2E suite.
 *
 * `webServer` blocks spin up dependencies before any test runs:
 *   1. The mock VCR API server on :9876
 *   2. (We assume wp-env was started by the test runner / CI step
 *      before invoking playwright — keeping it out of webServer
 *      avoids racing wp-env's own startup against the test timeout.)
 *
 * Single-worker, fully sequential — WP shares state across tests
 * (options, posts), so parallelism would surface as flakes from
 * cross-test pollution.
 */
export default defineConfig({
    testDir: './tests/E2E',
    testMatch: /.*\.spec\.mjs$/,
    // Every `wp ...` call boots WordPress, WooCommerce and this plugin inside
    // the container, which measures at 4-12s depending on machine load — and a
    // single scenario makes ten to fifteen of them. 60s fitted about twelve
    // calls on an idle laptop and nothing at all on a loaded CI runner, which
    // surfaced as a timeout in whichever spec happened to be longest that day.
    // The transport is not the cost (docker compose exec on the already-running
    // container saves ~40% and is still seconds), so the budget has to match
    // what the work costs. A hang is still caught: this fires long before the
    // job's own 25-minute cap.
    timeout: 150_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['github'], ['list']] : 'list',

    use: {
        baseURL: process.env.WP_BASE_URL ?? 'http://localhost:8888',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
    },

    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                launchOptions: {
                    // `host.docker.internal` is how the plugin, inside the
                    // wp-env container, reaches the mock on this machine — so
                    // it is also the host the consent URL comes back on, and
                    // the plugin refuses one on any other host. The name does
                    // not resolve outside the container, so the test browser
                    // is told what it actually is. Scoped to this browser:
                    // nothing is written to /etc/hosts.
                    args: ['--host-resolver-rules=MAP host.docker.internal 127.0.0.1'],
                },
            },
        },
    ],

    webServer: [
        {
            command: 'npm run mock:start',
            port: 9876,
            reuseExistingServer: !process.env.CI,
            stdout: 'pipe',
            stderr: 'pipe',
        },
    ],
});
