/**
 * Browser-side helpers for the specs that drive wp-admin.
 */

import { expect } from '@playwright/test';

/** WooCommerce -> Settings -> VCR. */
export const SETTINGS_PATH = '/wp-admin/admin.php?page=wc-settings&tab=vcr';

/**
 * Logs the admin in, once per file.
 *
 * The wait for focus is load-bearing rather than defensive. WordPress's login
 * page calls `wp_attempt_focus()` from a `setTimeout`, which focuses AND
 * selects `#user_login` a couple of hundred milliseconds after load — fill the
 * fields before that fires and the selection swallows the second value, so the
 * username ends up reading "password" and the password field empty. That is
 * exactly what the failure screenshot showed when this spec ran after the rest
 * of the suite, i.e. when the machine was loaded enough for the race to land
 * the other way.
 */
export async function logIn(page) {
    await page.goto('/wp-login.php');

    await page
        .waitForFunction(() => document.activeElement?.id === 'user_login', null, { timeout: 5000 })
        .catch(() => {
            // Older or filtered login screens may never focus anything. The
            // fills below are still correct; only the race is unguarded.
        });

    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');

    // Cheap, and it fails here with a clear message instead of thirty seconds
    // later as "waiting for navigation".
    await expect(page.locator('#user_login')).toHaveValue('admin');

    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/);
}
