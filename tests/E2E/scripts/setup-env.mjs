/**
 * Prepare the wp-env container for a Playwright run. Invoked by
 * `npm run env:setup`, and automatically as `pretest:e2e`.
 *
 * Why this exists instead of `.wp-env.json`'s `plugins` array:
 *
 *   1. **WooCommerce needs its canonical directory name.** wp-env derives a
 *      URL-sourced plugin's directory from the URL basename, so
 *      `woocommerce.latest-stable.zip` installs to
 *      `wp-content/plugins/woocommerce.latest-stable`. WordPress resolves
 *      `Requires Plugins: woocommerce` against *directory* names
 *      (WP_Plugin_Dependencies::get_dependency_filepaths), so under that
 *      layout the dependency reads as unmet — a difference from every real
 *      store, in the one header that decides whether the plugin may
 *      activate at all. `wp plugin install woocommerce` always lands in
 *      `wp-content/plugins/woocommerce`, and takes `--version` besides.
 *
 *   2. **The plugin under test is the built artefact**, mounted read-only
 *      from `build/e2e/` by the `mappings` block. Mounting rather than
 *      listing it under `plugins` keeps wp-env out of the activation
 *      ordering business: nothing is activated until WooCommerce is in
 *      place, which is the order a merchant installs them in.
 *
 * Environment:
 *
 *   WC_VERSION         WooCommerce version to install ("latest" by default).
 *   WC_ORDER_STORAGE   "hpos" (default) or "posts" — which table is
 *                      authoritative for orders. Both are live in the wild:
 *                      HPOS is the default for new stores since WC 8.2,
 *                      legacy post storage is what every store predating it
 *                      still runs until it migrates.
 */

import { evalFile, wpCli } from '../helpers/wp-cli.mjs';

const WC_VERSION = process.env.WC_VERSION ?? 'latest';
const ORDER_STORAGE = process.env.WC_ORDER_STORAGE ?? 'hpos';
const PLUGIN_SLUG = 'vcr-am-fiscal-receipts';

if (ORDER_STORAGE !== 'hpos' && ORDER_STORAGE !== 'posts') {
    throw new Error(`WC_ORDER_STORAGE must be "hpos" or "posts", got "${ORDER_STORAGE}"`);
}

async function reportWordPressVersion() {
    const version = await wpCli(['core', 'version']);
    console.log(`  WordPress ${version}`);
}

async function installWooCommerce() {
    const args = ['plugin', 'install', 'woocommerce', '--activate'];

    if (WC_VERSION !== 'latest') {
        // --force so a container carrying a different version from a previous
        // matrix leg is overwritten rather than silently kept.
        args.push(`--version=${WC_VERSION}`, '--force');
    }

    await wpCli(args);

    const version = await wpCli(['plugin', 'get', 'woocommerce', '--field=version']);
    console.log(`  WooCommerce ${version} installed and active`);
}

/**
 * Ask WooCommerce which table is actually authoritative right now.
 *
 * Read through WooCommerce's own accessor rather than the options we write
 * — a renamed option, or a version that ignores it, would otherwise leave
 * both matrix legs testing the same storage and reporting two passes.
 */
async function effectiveOrderStorage() {
    const answer = await wpCli([
        'eval',
        'echo \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? "hpos" : "posts";',
    ]);

    return answer;
}

/**
 * Switch the order datastore. The feature flag and the "which table is
 * authoritative" flag are separate options in WooCommerce and both have to
 * be set; `WC_Install::install()` then creates the HPOS tables, which a
 * site installed with the feature off does not have.
 */
async function configureOrderStorage() {
    const hpos = ORDER_STORAGE === 'hpos';

    if (await effectiveOrderStorage() !== ORDER_STORAGE) {
        // WooCommerce refuses to move the authoritative table while any
        // order is out of sync between the two, and every order this suite
        // creates is out of sync by construction (data sync is off). CI runs
        // each leg in a fresh container and never hits this; a developer
        // switching storage on a container they have already tested against
        // does, every time.
        const deleted = await evalFile('delete-all-orders.php');
        console.log(`  Switching storage — deleted ${deleted.trim()} existing order(s)`);
    }

    await wpCli(['option', 'update', 'woocommerce_feature_custom_order_tables_enabled', hpos ? 'yes' : 'no']);
    await wpCli(['option', 'update', 'woocommerce_custom_orders_table_enabled', hpos ? 'yes' : 'no']);
    // Sync off in both directions: with it on, an assertion could pass by
    // reading a mirrored copy while the plugin wrote to the other table.
    await wpCli(['option', 'update', 'woocommerce_custom_orders_table_data_sync_enabled', 'no']);

    if (hpos) {
        await wpCli(['eval', 'WC_Install::install();']);
    }

    const effective = await effectiveOrderStorage();

    if (effective !== ORDER_STORAGE) {
        throw new Error(
            `asked WooCommerce for "${ORDER_STORAGE}" order storage, it reports "${effective}"`,
        );
    }

    console.log(`  Order storage: ${effective}`);
}

async function activatePlugin() {
    // `mappings` is a Docker bind mount onto build/e2e/<slug>, and
    // `npm run build:e2e-plugin` deletes that directory before unzipping the
    // fresh ZIP into it. Do that while the containers are up and the mount is
    // left pointing at an inode nobody can reach: the directory is still
    // listed inside the container and it is empty, so WordPress sees no plugin
    // there and wp-cli says only "could not be found".
    //
    // `env:start` does not repair it — with an unchanged config wp-env reuses
    // the running containers, mount and all. Only a stop (or destroy) makes
    // Docker establish the mount again. Both were measured.
    const installed = await wpCli(['plugin', 'list', '--field=name']);

    if (! installed.split('\n').includes(PLUGIN_SLUG)) {
        throw new Error(
            `${PLUGIN_SLUG} is not visible inside the container: its directory `
            + 'is mounted but empty, which is what a ZIP rebuilt underneath a '
            + 'running wp-env looks like. Run `npm run env:stop && npm run '
            + 'env:start` — starting alone reuses the containers and keeps the '
            + 'stale mount.',
        );
    }

    await wpCli(['plugin', 'activate', PLUGIN_SLUG]);

    const status = await wpCli(['plugin', 'get', PLUGIN_SLUG, '--field=status']);

    if (status !== 'active') {
        throw new Error(`${PLUGIN_SLUG} is "${status}" after activation`);
    }

    const version = await wpCli(['plugin', 'get', PLUGIN_SLUG, '--field=version']);
    console.log(`  ${PLUGIN_SLUG} ${version} active (built artefact from build/e2e/)`);
}

console.log('Preparing the wp-env container:');
// The two versions the run actually exercised, printed before anything else:
// the matrix asks for "latest", so the log is the only record of which
// release that was — and the `Tested up to` / `WC tested up to` headers we
// publish are a claim about exactly these two numbers.
await reportWordPressVersion();
await installWooCommerce();
await configureOrderStorage();
await activatePlugin();
