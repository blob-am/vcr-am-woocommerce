# VCR — Fiscal Receipts for Armenia (eHDM) — WooCommerce plugin

[![Latest Version](https://img.shields.io/github/v/tag/blob-am/vcr-am-woocommerce?label=version&sort=semver)](https://github.com/blob-am/vcr-am-woocommerce/releases)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)

Official WooCommerce plugin for the [VCR.AM](https://vcr.am) Virtual Cash Register — issue Armenian fiscal receipts (eHDM) directly to the State Revenue Committee from WooCommerce orders.

> **Status:** the fiscal flow is built and covered by tests, but the plugin has never run against a live SRC register — every end-to-end test so far has been against a mock. It is unreleased (no tag, no WordPress.org listing) and wants a supervised pilot on a real store before anyone depends on it. See [Roadmap](#roadmap).

## Why this plugin

Armenian merchants selling online must issue an electronic fiscal receipt (e-HDM) for every sale, refund, and prepayment, per Tax Code Article 380.1 (HO-280-N) and Government Decision 1976-N. This plugin wires that obligation into the standard WooCommerce checkout / refund / order-management flow.

What sets it apart from existing options:

- **Direct SRC integration** — talks to the official VCR.AM gateway, not to a third-party reseller. No per-receipt rake from intermediaries.
- **Asynchronous fiscalization** — uses WooCommerce's Action Scheduler. Customer checkout is never blocked by SRC slowness; failed transmissions retry automatically with exponential backoff.
- **Multi-currency first-class** — orders in USD/EUR/RUB are sent to the VCR per-line in their own currency; the VCR converts each line to AMD server-side at the previous-business-day Central Bank of Armenia rate and records the HO-234-N foreign-input audit trail. The whole AMD total is derived and settled server-side (auto-settle), so the plugin never guesses the AMD magnitude. (Refunds, which reverse an already-AMD receipt, resolve the AMD amount through the plugin's own cached CBA rate with stale-rate guards.)
- **Refund-aware** — a full refund of an order is reversed at the tax authority automatically. A partial refund is flagged for you to register by hand, because the reversal has to name the exact lines and the SDK does not yet expose per-item SRC ids.
- **Customer-facing receipt** — a verification link to the official receipt page on the thank-you page, in transactional emails and in order details.
- **HPOS + Cart/Checkout Blocks compatible** out of the box.
- **Encrypted credentials at rest** (libsodium).

## Requirements

| | Minimum | Tested up to |
| --- | --- | --- |
| WordPress | 6.7 | 6.8 |
| WooCommerce | 9.4 | 10.7 |
| PHP | 8.3 | 8.4 |

## Installation

Download `vcr-am-fiscal-receipts.zip` from the [latest release](https://github.com/blob-am/vcr-am-woocommerce/releases/latest), then in WordPress go to **Plugins -> Add New -> Upload Plugin** and upload it.

> Do not install from the green **Code -> Download ZIP** button, and do not upload a `git clone` of this repository. The source tree deliberately excludes `vendor/` and `vendor-prefixed/`, so WordPress will activate the plugin and immediately show *"missing composer dependencies (run composer install)"*. The release ZIP is the same tree with those directories built in.

After activating, configure it under **WooCommerce -> Settings -> VCR** (steps 3-5 of the Installation section in `readme.txt`).

## Installation (development)

```bash
git clone git@github.com:blob-am/vcr-am-woocommerce.git
cd vcr-am-woocommerce
composer install
```

> [Strauss](https://github.com/BrianHenryIE/strauss) scopes production dependencies into the `BlobSolutions\WooCommerceVcrAm\Vendor\` namespace under `vendor-prefixed/`. Required for WP.org distribution, so that two plugins bundling the same library at different versions cannot collide. **Everything is scoped, the PSR interfaces included** — `psr/log` v1 and v3 have mutually unsatisfiable signatures, so a global `Psr\Log\LoggerInterface` is a collision, not a shared contract, and both WordPress core and WooCommerce core scope their own. The shipped `vendor/` therefore holds nothing but the plugin's own autoloader. Note the consequence for development: the plugin calls the *vendored* copy of the PHP SDK, so a new SDK capability is unavailable here until the SDK is released and re-vendored.

## Repository layout

```
vcr-am-woocommerce/
├── vcr-am-fiscal-receipts.php   ← plugin entry (matches the WP.org slug)
├── composer.json                 ← deps + Strauss config + scripts
├── phpstan.neon.dist             ← static analysis (level max + strict rules)
├── pint.json                     ← code style (Pint, PSR-12 + extras)
├── phpunit.xml.dist              ← test harness
├── readme.txt                    ← WordPress.org plugin directory readme
├── LICENSE                       ← GPL-2.0-or-later (WP.org requirement)
├── src/
│   ├── Plugin.php                ← bootstrap + wiring (HPOS / Blocks declarations, WC active guard)
│   ├── Configuration.php         ← every stored option, read through one class
│   ├── Admin/                    ← order meta box, orders-list column, bulk action, system status
│   ├── Catalog/                  ← cashier and department lookups against the VCR account
│   ├── Cli/                      ← WP-CLI commands
│   ├── Currency/                 ← CBA rate fetch + cache for non-AMD orders
│   ├── Fiscal/                   ← the sale pipeline: listener → queue → job → SDK
│   ├── Logging/                  ← log routing
│   ├── Migration/                ← option/meta upgrades between plugin versions
│   ├── Net/                      ← HTTP client plumbing, incl. the SSRF guard
│   ├── Privacy/                  ← GDPR exporter and eraser
│   ├── Receipt/                  ← customer-facing receipt link
│   ├── Refund/                   ← the refund pipeline, parallel to Fiscal/
│   ├── Settings/                 ← the WooCommerce settings tab
│   └── VcrClientFactory.php      ← builds the vendored SDK client from settings
└── tests/
    ├── Pest.php
    ├── TestCase.php              ← Brain Monkey set up / tear down
    ├── Unit/                     ← mirrors src/, one directory per namespace
    └── E2E/                      ← Playwright against wp-env + a mock VCR server
```

## Local development

```bash
composer check          # full local CI: format + phpstan + tests
composer test           # Pest test suite
composer phpstan        # static analysis
composer format         # apply Pint code style fixes
composer format:check   # verify code style without writing
composer strauss        # re-run Strauss (auto-runs after install/update)
```

### End-to-end tests

The repo ships an E2E suite that runs the plugin against a real WordPress + WooCommerce stack via `wp-env`, with a tiny in-process mock VCR API server. No production VCR endpoint is touched.

**The suite drives the built ZIP, not the working tree.** `npm run env:start` builds `dist/vcr-am-fiscal-receipts.zip` first and unpacks it into `build/e2e/`, and that is what WordPress loads. Running the tree instead is how v0.1.0 shipped green and unable to make a single HTTP call: the tree's `vendor/` is the developer's unscoped one, so the autoloader defect that only exists after Strauss runs was invisible to every test.

Requirements: Node 20+, Docker.

```bash
npm install                    # one-off: pulls @wordpress/env + @playwright/test
npm run test:e2e:install       # one-off: downloads chromium for Playwright

npm run env:start              # build the ZIP, then boot WP + WC + it in Docker
npm run test:e2e               # run the suite (mock VCR auto-starts via webServer)
npm run env:stop               # tear down
```

Two environment variables select what the suite runs against:

| Variable | Default | What |
| --- | --- | --- |
| `WC_ORDER_STORAGE` | `hpos` | `hpos` or `posts` — which table WooCommerce treats as authoritative for orders. CI runs both. |
| `WC_VERSION` | `latest` | WooCommerce version to install. |

```bash
WC_ORDER_STORAGE=posts npm run test:e2e   # legacy post storage
```

Switching storage against a container that already has orders deletes them: WooCommerce refuses to move the authoritative table while any order is out of sync, and a test store has nothing worth migrating.

Layout:

| Path | What |
| --- | --- |
| `.wp-env.json` | wp-env config — pins PHP 8.3, mounts `build/e2e/` + the mu-plugin + the fixture scripts |
| `tests/E2E/scripts/setup-env.mjs` | Installs and activates WooCommerce, sets the order datastore, activates the plugin. Runs as `pretest:e2e` |
| `tests/E2E/mu-plugins/vcr-e2e-bootstrap.php` | mu-plugin loaded inside WP — points the plugin at the mock VCR |
| `tests/E2E/mock-vcr-server.mjs` | Node HTTP server on `:9876` answering `/api/v1/cashiers`, `/api/v1/sales`, `/api/v1/sales/refund`, `/api/v1/exchange-rate`. Programmable per test via `/__test/plan` |
| `tests/E2E/helpers/wp-cli.mjs` | `wp ...` runner, plus the storage-agnostic order-meta helpers |
| `tests/E2E/helpers/mock-vcr.mjs` | Test-side client for `/__test/log` (assertions) and `/__test/plan` (scenario programming) |
| `tests/E2E/scripts/*.php` | Fixtures run via `wp eval-file` — create orders, read back `_vcr_*` meta, reset state |
| `tests/E2E/*.spec.mjs` | Playwright test specs |

WooCommerce is installed by `wp plugin install`, not listed in `.wp-env.json`. A URL-sourced plugin lands in a directory named after the URL (`woocommerce.latest-stable`), and WordPress resolves `Requires Plugins: woocommerce` against directory names — so under that layout the suite tests a dependency graph no real store has.

Order meta is read through WooCommerce's order CRUD (`tests/E2E/scripts/read-order-meta.php`), never `wp post meta list`. Under HPOS `wp_postmeta` holds nothing, so a postmeta read asserts against an empty set and passes having verified nothing.

#### WP/WC version policy

CI matrix today is `php 8.3 × WP latest × WC latest`, run against both order datastores. Once the suite proves stable on `main` for a couple of weeks, broaden to:

- PHP: 8.3, 8.4
- WP: 6.6, 6.7, latest
- WC: 9.4, latest, beta

Matrix combos run in parallel. The narrow start lets us iterate on flake without burning CI minutes; expand by editing the `matrix:` block in `.github/workflows/e2e.yml`.

## Architecture principles

This plugin follows the same conventions as the rest of the VCR.AM ecosystem:

- **Domain over wire.** SRC field names (`adgCode`, `goodCode`, `uniqueCode`) live exclusively inside `blob-solutions/vcr-am-sdk` and below. Plugin-side surfaces — admin UI, customer UI, WC product meta — speak in domain terms (`department`, `unit`, `eMark codes`).
- **Async over sync.** Fiscal API calls never run on the request thread. Action Scheduler queues jobs; SRC outages never block customer checkout.
- **Idempotent over best-effort.** Every fiscal job carries a deterministic `external_id` derived from the WC order ID, so retries and double-fires produce one receipt.
- **Observable over silent.** Every order surfaces fiscal state (`pending` / `queued` / `success` / `failed` / `manual_required`), every transition leaves a WC order note, every failed retry surfaces an admin notice.

## Roadmap

| Phase | Scope | Status |
| --- | --- | --- |
| 1 | Repo scaffold, tooling, plugin shell, HPOS / Blocks declarations | ✅ done |
| 2 | SDK + Guzzle as production deps, Strauss vendor scoping, core fiscal flow (order-status hooks, Action Scheduler queue), settings page | ✅ done |
| 3 | FX handling — CBA rate fetcher with cache + stale-rate guards | ✅ done |
| 4 | Refund automation (full refunds), customer-facing receipt link on thank-you page and emails | ✅ done |
| 5 | E2E suite via wp-env + Playwright, against a mock VCR server | ✅ done |
| 6 | Validate against a live register on a real store; first tagged release | next |
| 7 | Idempotency key on every submission — blocked on a PHP SDK release carrying it | next |
| 8 | Partial refunds (needs per-item SRC ids from the SDK), B2B buyer, per-product unit, `hy_AM` / `ru_RU` translations, QR code, WordPress.org submission | planned |

Deliberately out of scope for now: excise marks (eMark), so shops selling alcohol, tobacco or pharmaceuticals cannot use this plugin yet; prepayment receipts; mixed/split tender.

## Related packages

- **[blob-solutions/vcr-am-sdk](https://packagist.org/packages/blob-solutions/vcr-am-sdk)** — framework-agnostic PHP SDK (this plugin's HTTP backbone).
- **[blob-solutions/laravel-vcr-am](https://packagist.org/packages/blob-solutions/laravel-vcr-am)** — Laravel adapter built on the same SDK.
- **[@blob-solutions/vcr-am-sdk](https://www.npmjs.com/package/@blob-solutions/vcr-am-sdk)** — TypeScript SDK (Node.js).

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Required by WordPress.org plugin directory and consistent with WooCommerce itself.
